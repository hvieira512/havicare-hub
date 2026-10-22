<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use Hub\Command\Configuration\Payload\VivistarPayloadBuilder;
use PDO;

/** O `working_mode` guardado das Vivistar passa do número do `BP33` ao nome; cada escrita leva o valor antigo. */
final class VivistarWorkingModeNames implements Migration
{
    public function version(): string
    {
        return '2026_10_22_vivistar_working_mode_names';
    }

    public function up(PDO $pdo): void
    {
        $rows = $pdo->query("
            SELECT imei, native_key, desired_payload FROM device_configurations
            WHERE protocol = 'vivistar-iw' AND config_key = 'working_mode'
        ")->fetchAll(PDO::FETCH_ASSOC);
        $update = $pdo->prepare("
            UPDATE device_configurations SET desired_payload = ?
            WHERE imei = ? AND config_key = 'working_mode' AND native_key = ? AND desired_payload = ?
        ");
        foreach ($rows as $row) {
            $named = self::named((string)$row['desired_payload']);
            if ($named !== null) {
                $update->execute([$named, $row['imei'], $row['native_key'], $row['desired_payload']]);
            }
        }

        $changes = $pdo->query("
            SELECT change_id, desired_payload, effective_payload FROM device_configuration_changes
            WHERE config_key = 'working_mode' AND imei IN (SELECT imei FROM device_configurations WHERE protocol = 'vivistar-iw')
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach (['desired_payload', 'effective_payload'] as $column) {
            $update = $pdo->prepare("UPDATE device_configuration_changes SET {$column} = ? WHERE change_id = ? AND {$column} = ?");
            foreach ($changes as $change) {
                $named = self::named((string)($change[$column] ?? ''));
                if ($named !== null) {
                    $update->execute([$named, $change['change_id'], $change[$column]]);
                }
            }
        }
    }

    /** O payload com o modo pelo nome, ou `null` quando já lá está. */
    private static function named(string $json): ?string
    {
        $payload = json_decode($json, true);
        $mode = is_array($payload) && is_numeric($payload['mode'] ?? null)
            ? array_search((int)$payload['mode'], VivistarPayloadBuilder::WORKING_MODES, true)
            : false;
        if ($mode === false) {
            return null;
        }

        return (string)json_encode(['mode' => $mode] + $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
