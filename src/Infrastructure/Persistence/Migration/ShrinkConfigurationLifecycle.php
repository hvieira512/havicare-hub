<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use Hub\Api\Configuration\VoiceDataMarker;
use PDO;

/**
 * Larga o que ninguém lia e põe dois índices na ordem em que são procurados.
 *
 * Três colunas de `device_configuration_operations` eram escritas e nunca lidas. A
 * `command_bytes` guardava os bytes do comando outra vez -- a entrega faz-se pela fila
 * `hub:downlink` do Redis, com TTL, e é lá que estão os bytes que saem no fio. A
 * `expected_reply_types` acompanhava-a, e a `retry_delay_seconds` não aparecia uma única vez
 * no código, nem escrita.
 *
 * Cinco índices não podiam servir consulta nenhuma: dois pela ordem das colunas, dois por
 * serem prefixo exacto de um índice mais forte, e o `..._dispatch` por prometer um despachante
 * que não existe -- a tabela só é lida por `change_id`.
 *
 * E o áudio sai do histórico. O aviso de medicação da 4P Touch trazia até 978 KB de MP3 em
 * base64 por revisão, o que fazia 69% da base de produção. Fica a marca que a API já falava.
 * O estado corrente mantém a gravação, porque é a base de fusão de uma alteração parcial.
 *
 * A marcação usa o `VoiceDataMarker` de propósito: é o mesmo código que escreve as linhas
 * novas, e assim as antigas e as novas não podem divergir de forma.
 */
final class ShrinkConfigurationLifecycle implements Migration
{
    /** Os índices que saem, por tabela. */
    private const DROPPED_INDEXES = [
        'device_configuration_operations' => ['idx_configuration_operation_dispatch'],
        'device_configurations' => ['idx_device_config_current_change'],
        'private_radio_map_access_points' => ['idx_private_radio_map_usable'],
        'model_capabilities' => ['idx_model_capabilities_model'],
        'licenses' => ['idx_licenses_company_id'],
        'whitelist' => ['idx_whitelist_device_type_license'],
    ];

    /** Os que entram no lugar de dois deles, agora pela ordem em que são procurados. */
    private const ADDED_INDEXES = [
        'device_configurations' => ['idx_device_config_change' => '(current_change_id)'],
        'whitelist' => ['idx_whitelist_license_device_type' => '(license_id, device_type)'],
    ];

    private const DROPPED_COLUMNS = ['command_bytes', 'expected_reply_types', 'retry_delay_seconds'];

    public function version(): string
    {
        return '2026_09_03_shrink_configuration_lifecycle';
    }

    public function up(PDO $pdo): void
    {
        foreach (self::DROPPED_INDEXES as $table => $indexes) {
            foreach ($indexes as $index) {
                if ($this->hasIndex($pdo, $table, $index)) {
                    $pdo->exec("DROP INDEX {$index} ON {$table}");
                }
            }
        }

        foreach (self::ADDED_INDEXES as $table => $indexes) {
            foreach ($indexes as $index => $columns) {
                if (!$this->hasIndex($pdo, $table, $index)) {
                    $pdo->exec("CREATE INDEX {$index} ON {$table} {$columns}");
                }
            }
        }

        foreach (self::DROPPED_COLUMNS as $column) {
            if ($this->hasColumn($pdo, 'device_configuration_operations', $column)) {
                $pdo->exec("ALTER TABLE device_configuration_operations DROP COLUMN {$column}");
            }
        }

        $this->markStoredVoiceData($pdo);
    }

    /**
     * Percorre uma alteração de cada vez: um payload chega a 978 KB, e carregá-los todos de
     * uma vez para memória não traz nada.
     */
    private function markStoredVoiceData(PDO $pdo): void
    {
        $marker = new VoiceDataMarker();
        $ids = $pdo
            ->query("
                SELECT change_id FROM device_configuration_changes
                WHERE desired_payload LIKE '%\"voiceData\"%'
                   OR effective_payload LIKE '%\"voiceData\"%'
            ")
            ->fetchAll(PDO::FETCH_COLUMN);

        $read = $pdo->prepare('
            SELECT desired_payload, effective_payload
            FROM device_configuration_changes WHERE change_id = ?
        ');
        $write = $pdo->prepare('
            UPDATE device_configuration_changes
            SET desired_payload = ?, effective_payload = ? WHERE change_id = ?
        ');

        foreach ($ids as $changeId) {
            $read->execute([$changeId]);
            $row = $read->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                continue;
            }

            $desired = $this->reencode($marker, (string)$row['desired_payload']);
            $effective = $row['effective_payload'] === null
                ? null
                : $this->reencode($marker, (string)$row['effective_payload']);
            $write->execute([$desired, $effective, $changeId]);
        }
    }

    /** Um payload que não seja JSON de objecto fica exactamente como está. */
    private function reencode(VoiceDataMarker $marker, string $payload): string
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return $payload;
        }

        return json_encode($marker->mark($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $payload;
    }

    private function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
        ');
        $stmt->execute([$table, $column]);

        return (int)$stmt->fetchColumn() > 0;
    }

    private function hasIndex(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?
        ');
        $stmt->execute([$table, $index]);

        return (int)$stmt->fetchColumn() > 0;
    }
}
