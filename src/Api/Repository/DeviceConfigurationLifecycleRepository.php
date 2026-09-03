<?php

declare(strict_types=1);

namespace Hub\Api\Repository;

use Hub\Api\Configuration\VoiceDataMarker;
use Hub\Infrastructure\Persistence\TimestampFormatter;
use PDO;

final class DeviceConfigurationLifecycleRepository
{
    private VoiceDataMarker $voiceDataMarker;

    public function __construct(private PDO $pdo)
    {
        $this->voiceDataMarker = new VoiceDataMarker();
    }

    /**
     * @param list<array<string,mixed>> $nativeRows
     * @param list<array<string,mixed>> $operations
     * @return array{changeId:string,revision:int,operations:list<array<string,mixed>>}
     */
    public function stage(
        string $imei,
        string $genericKey,
        array $desired,
        array $nativeRows,
        array $operations,
    ): array {
        $now = gmdate('Y-m-d H:i:s');
        $changeId = bin2hex(random_bytes(16));
        $this->pdo->beginTransaction();
        try {
            $revisionStmt = $this->pdo->prepare('
                SELECT COALESCE(MAX(desired_revision), 0) + 1
                FROM device_configuration_changes
                WHERE imei = ? AND config_key = ?
                FOR UPDATE
            ');
            $revisionStmt->execute([$imei, $genericKey]);
            $revision = max(1, (int)$revisionStmt->fetchColumn());

            $supersede = $this->pdo->prepare("
                UPDATE device_configuration_changes
                SET sync_status = 'superseded', superseded_at = ?, updated_at = ?
                WHERE imei = ? AND config_key = ? AND superseded_at IS NULL
            ");
            $supersede->execute([$now, $now, $imei, $genericKey]);
            $supersedeOps = $this->pdo->prepare("
                UPDATE device_configuration_operations operation_row
                JOIN device_configuration_changes change_row ON change_row.change_id = operation_row.change_id
                SET operation_row.delivery_status = 'superseded',
                    operation_row.updated_at = ?
                WHERE change_row.imei = ? AND change_row.config_key = ?
                  AND operation_row.delivery_status NOT IN ('acked', 'failed', 'superseded')
            ");
            $supersedeOps->execute([$now, $imei, $genericKey]);

            $insertChange = $this->pdo->prepare('
                INSERT INTO device_configuration_changes (
                    change_id, imei, config_key, desired_revision, desired_payload,
                    sync_status, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $insertChange->execute([
                $changeId,
                $imei,
                $genericKey,
                $revision,
                // O histórico leva a marca do áudio e não o áudio: quem lê uma revisão quer
                // saber o que mudou. O estado corrente, abaixo, guarda-o por inteiro.
                $this->encode($this->voiceDataMarker->mark($desired)),
                $operations === [] ? 'confirmed' : 'pending_delivery',
                $now,
                $now,
            ]);

            // Sem o fornecedor nem o modelo: o dono do IMEI é a `whitelist`, e uma cópia aqui
            // chegou a declarar dois modelos para o mesmo aparelho.
            $upsert = $this->pdo->prepare('
                INSERT INTO device_configurations (
                    imei, config_key, native_key, protocol, command,
                    desired_payload, reported_payload, desired_revision,
                    current_change_id, confirmation_mode, last_status, last_error,
                    last_command_id, desired_updated_at, reported_at, applied_at
                ) VALUES (?, ?, ?, ?, ?, ?, \'{}\', ?, ?, ?, ?, \'\', ?, ?, NULL, NULL)
                ON DUPLICATE KEY UPDATE
                    protocol = VALUES(protocol),
                    command = VALUES(command), desired_payload = VALUES(desired_payload),
                    desired_revision = VALUES(desired_revision), current_change_id = VALUES(current_change_id),
                    confirmation_mode = VALUES(confirmation_mode), last_status = VALUES(last_status),
                    last_error = \'\', last_command_id = VALUES(last_command_id),
                    desired_updated_at = VALUES(desired_updated_at)
            ');
            foreach ($nativeRows as $row) {
                $upsert->execute([
                    $imei, $genericKey, (string)$row['nativeKey'], (string)$row['protocol'],
                    (string)$row['command'],
                    $this->encode((array)$row['payload']), $revision, $changeId,
                    (string)$row['confirmationMode'],
                    $operations === [] ? 'acked' : 'created',
                    (string)($row['operationId'] ?? ''), $now,
                ]);
            }

            // Sem os bytes do comando: quem os entrega é a fila `hub:downlink` do Redis, e
            // aqui fica o registo do que foi pedido e como correu. Sem o `imei` nem o
            // `config_key`: são da alteração, e a chave estrangeira dá o caminho.
            $insertOperation = $this->pdo->prepare('
                INSERT INTO device_configuration_operations (
                    operation_id, change_id, native_key, native_type,
                    protocol, confirmation_mode,
                    delivery_status, created_at, updated_at, sequence_number
                ) VALUES (?, ?, ?, ?, ?, ?, \'created\', ?, ?, ?)
            ');
            foreach ($operations as $index => &$operation) {
                $operation['changeId'] = $changeId;
                $operation['desiredRevision'] = $revision;
                $operation['configKey'] = $genericKey;
                $insertOperation->execute([
                    $operation['operationId'], $changeId,
                    $operation['nativeKey'], $operation['nativeType'], $operation['protocol'],
                    $operation['confirmationMode'], $now, $now, $index,
                ]);
            }
            unset($operation);
            $this->pdo->commit();
            return ['changeId' => $changeId, 'revision' => $revision, 'operations' => $operations];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function updateOperation(string $operationId, string $status, string $error = ''): bool
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('
            UPDATE device_configuration_operations operation_row
            JOIN device_configuration_changes change_row ON change_row.change_id = operation_row.change_id
            SET operation_row.delivery_status = ?, operation_row.error_code = ?,
                operation_row.updated_at = ?,
                operation_row.attempts = operation_row.attempts + IF(? IN (\'queued\', \'waiting\', \'failed\'), 1, 0),
                operation_row.sent_at = IF(? = \'waiting\', ?, operation_row.sent_at),
                operation_row.acknowledged_at = IF(? = \'acked\', ?, operation_row.acknowledged_at)
            WHERE operation_row.operation_id = ?
              AND change_row.superseded_at IS NULL
        ');
        $stmt->execute([$status, $error, $now, $status, $status, $now, $status, $now, $operationId]);
        if ($stmt->rowCount() === 0) {
            return false;
        }
        $this->refreshChangeForOperation($operationId);
        return true;
    }

    public function isCurrentOperation(string $operationId): bool
    {
        $stmt = $this->pdo->prepare('
            SELECT COUNT(*)
            FROM device_configuration_operations operation_row
            JOIN device_configuration_changes change_row ON change_row.change_id = operation_row.change_id
            WHERE operation_row.operation_id = ? AND change_row.superseded_at IS NULL
        ');
        $stmt->execute([$operationId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /** @return list<array<string,mixed>> */
    public function currentForImei(string $imei): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM device_configuration_changes
            WHERE imei = ? AND superseded_at IS NULL
            ORDER BY config_key
        ");
        $stmt->execute([$imei]);
        $changes = [];
        foreach ($stmt->fetchAll() as $change) {
            $change['desired_payload'] = $this->decode((string)$change['desired_payload']);
            $change['effective_payload'] = $change['effective_payload'] === null
                ? null : $this->decode((string)$change['effective_payload']);
            // As colunas são `DATETIME NULL`; a API fala ISO com `Z`, e diz "ainda não" com
            // cadeia vazia. A conversão é aqui, na fronteira.
            $change = TimestampFormatter::isoColumns(
                $change,
                ['created_at', 'updated_at', 'confirmed_at', 'superseded_at']
            );
            $change['operations'] = $this->operations((string)$change['change_id']);
            $changes[] = $change;
        }
        return $changes;
    }

    /** @return list<array<string,mixed>> */
    private function operations(string $changeId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT operation_id, native_key, native_type, confirmation_mode, delivery_status,
                   error_code, attempts, max_attempts, created_at, updated_at, sent_at, acknowledged_at
            FROM device_configuration_operations
            WHERE change_id = ? ORDER BY sequence_number
        ');
        $stmt->execute([$changeId]);

        return array_map(
            static fn(array $row): array => TimestampFormatter::isoColumns(
                $row,
                ['created_at', 'updated_at', 'sent_at', 'acknowledged_at']
            ),
            $stmt->fetchAll()
        );
    }

    private function refreshChangeForOperation(string $operationId): void
    {
        $stmt = $this->pdo->prepare('SELECT change_id FROM device_configuration_operations WHERE operation_id = ?');
        $stmt->execute([$operationId]);
        $changeId = (string)$stmt->fetchColumn();
        if ($changeId === '') {
            return;
        }
        $operations = $this->operations($changeId);
        $statuses = array_column($operations, 'delivery_status');
        $modes = array_column($operations, 'confirmation_mode');
        $sync = 'pending_delivery';
        if (array_intersect($statuses, ['failed', 'dropped'])) {
            $sync = 'failed';
        } elseif (array_intersect($statuses, ['created', 'queued'])) {
            $sync = 'pending_delivery';
        } elseif (array_intersect($statuses, ['waiting', 'sent'])) {
            $sync = 'awaiting_ack';
        } elseif ($statuses !== [] && count(array_unique($statuses)) === 1 && $statuses[0] === 'acked') {
            $sync = in_array('ack_only', $modes, true) ? 'confirmation_unavailable' : 'confirmed';
        }
        $now = gmdate('Y-m-d H:i:s');
        $confirm = $sync === 'confirmed';
        $update = $this->pdo->prepare('
            UPDATE device_configuration_changes
            SET sync_status = ?, effective_payload = IF(?, desired_payload, effective_payload),
                confirmed_at = IF(?, ?, confirmed_at), updated_at = ?
            WHERE change_id = ? AND superseded_at IS NULL
        ');
        $update->execute([$sync, $confirm ? 1 : 0, $confirm ? 1 : 0, $now, $now, $changeId]);
        $rows = $this->pdo->prepare('
            UPDATE device_configurations
            SET last_status = ?, last_error = ?,
                applied_at = IF(?, ?, applied_at)
            WHERE current_change_id = ?
        ');
        $error = '';
        foreach ($operations as $operation) {
            if ((string)$operation['error_code'] !== '') {
                $error = (string)$operation['error_code'];
                break;
            }
        }
        $rows->execute([$sync, $error, $confirm ? 1 : 0, $now, $changeId]);
    }

    private function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private function decode(string $value): array
    {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
