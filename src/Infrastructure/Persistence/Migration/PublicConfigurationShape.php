<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use Hub\Command\Configuration\Payload\FourPTouchPayloadBuilder;
use PDO;

/**
 * Passa as configurações guardadas à forma pública: os nomes da Wonlex, os códigos do 4P Touch e
 * os tipos numéricos das áreas do radar saem. Cada escrita leva o valor antigo na condição.
 */
final class PublicConfigurationShape implements Migration
{
    private const RENAMES = [
        'switchState' => 'enabled',
        'exerciseSwitchState' => 'exerciseEnabled',
        'intervalTime' => 'intervalSeconds',
        'Battery' => 'thresholdPercent',
        'reminderValue' => 'thresholdPercent',
        'RemindValue' => 'thresholdCelsius',
        'hpWarn' => 'systolicMmHg',
        'LPWarn' => 'diastolicMmHg',
        'remindValue' => 'thresholdBpm',
        'exerciseHRMin' => 'exerciseMinBpm',
        'exerciseHRMax' => 'exerciseMaxBpm',
        'exerciseRemindValue' => 'exerciseThresholdBpm',
        'sleepStartTime' => 'startTime',
        'sleepEndTime' => 'endTime',
        'sleepTarget' => 'targetMinutes',
        'sosNumbers' => 'numbers',
    ];

    private const AREA_TYPES = [1 => 'custom', 2 => 'bed', 3 => 'interference', 4 => 'door', 5 => 'monitoring_bed', 6 => 'alarm_area', 7 => 'furniture'];

    /** Respostas guardadas debaixo do tipo nativo, sem configuração pública a que pertençam. */
    private const NATIVE_TYPE_KEYS = ['AP40', 'AP43', 'CONFIG', 'upGetDevConfig'];

    public function version(): string
    {
        return '2026_10_21_public_configuration_shape';
    }

    public function up(PDO $pdo): void
    {
        $placeholders = implode(',', array_fill(0, count(self::NATIVE_TYPE_KEYS), '?'));
        $pdo->prepare("DELETE FROM device_configurations WHERE config_key IN ({$placeholders})")->execute(self::NATIVE_TYPE_KEYS);
        $pdo->prepare("DELETE FROM device_configuration_changes WHERE config_key IN ({$placeholders})")->execute(self::NATIVE_TYPE_KEYS);

        // A chave nativa é da chave primária: um despertador já guardado debaixo de `alarmClock` ganha.
        $pdo->exec("UPDATE IGNORE device_configurations SET native_key = 'alarmClock' WHERE protocol = 'wonlex-json' AND native_key = 'alarm_clock'");
        $pdo->exec("DELETE FROM device_configurations WHERE protocol = 'wonlex-json' AND native_key = 'alarm_clock'");

        $rows = $pdo->query('SELECT imei, config_key, native_key, desired_payload FROM device_configurations')->fetchAll(PDO::FETCH_ASSOC);
        $update = $pdo->prepare('
            UPDATE device_configurations SET desired_payload = ?
            WHERE imei = ? AND config_key = ? AND native_key = ? AND desired_payload = ?
        ');
        foreach ($rows as $row) {
            $reshaped = self::reshape((string)$row['config_key'], (string)$row['desired_payload']);
            if ($reshaped !== null) {
                $update->execute([$reshaped, $row['imei'], $row['config_key'], $row['native_key'], $row['desired_payload']]);
            }
        }

        $changes = $pdo->query('SELECT change_id, config_key, desired_payload, effective_payload FROM device_configuration_changes')->fetchAll(PDO::FETCH_ASSOC);
        foreach (['desired_payload', 'effective_payload'] as $column) {
            $update = $pdo->prepare("UPDATE device_configuration_changes SET {$column} = ? WHERE change_id = ? AND {$column} = ?");
            foreach ($changes as $change) {
                $reshaped = self::reshape((string)$change['config_key'], (string)($change[$column] ?? ''));
                if ($reshaped !== null) {
                    $update->execute([$reshaped, $change['change_id'], $change[$column]]);
                }
            }
        }
    }

    /** O payload na forma pública, ou `null` quando já lá está. */
    private static function reshape(string $configKey, string $json): ?string
    {
        $payload = json_decode($json, true);
        if (!is_array($payload) || $payload === []) {
            return null;
        }

        $reshaped = match ($configKey) {
            'sound_profile' => self::soundProfile($payload),
            'language_timezone' => self::languageTimezone($payload),
            'radar_areas' => self::radarAreas($payload),
            default => self::renamed($configKey, $payload),
        };

        return $reshaped === $payload ? null : (string)json_encode($reshaped, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<array-key, mixed> $payload
     * @return array<array-key, mixed>
     */
    private static function renamed(string $configKey, array $payload): array
    {
        $result = [];
        foreach ($payload as $field => $value) {
            $name = self::RENAMES[$field] ?? ($field === 'interval' && str_ends_with($configKey, '_interval') ? 'intervalMinutes' : $field);
            $result[$name] = match ($field) {
                'switchState', 'exerciseSwitchState' => (bool)$value,
                // O relógio guardava as horas em `HHmmss`.
                'sleepStartTime', 'sleepEndTime' => preg_match('/^(\d{2})(\d{2})\d{2}$/', (string)$value, $time) === 1
                    ? "{$time[1]}:{$time[2]}"
                    : $value,
                default => $value,
            };
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $payload
     * @return array<array-key, mixed>
     */
    private static function soundProfile(array $payload): array
    {
        $mode = array_search((int)($payload['mode'] ?? 0), FourPTouchPayloadBuilder::SOUND_PROFILES, true);

        return is_numeric($payload['mode'] ?? null) && $mode !== false ? ['mode' => $mode] + $payload : $payload;
    }

    /**
     * @param array<array-key, mixed> $payload
     * @return array<array-key, mixed>
     */
    private static function languageTimezone(array $payload): array
    {
        if (!is_numeric($payload['language'] ?? null) || !array_key_exists('timeZone', $payload)) {
            return $payload;
        }

        // A ficha repete o polaco no 29.
        $code = (int)$payload['language'] === 29 ? 17 : (int)$payload['language'];
        $language = array_search($code, FourPTouchPayloadBuilder::LANGUAGES, true);
        $offset = trim((string)$payload['timeZone']);
        if ($language === false || preg_match('/^([+-]?)(\d{1,2})(?::(\d{2}))?$|^([+-]?\d+(?:\.\d+)?)$/', $offset, $parts) !== 1) {
            return $payload;
        }
        $hours = isset($parts[4])
            ? (float)$parts[4]
            : ((int)$parts[2] + (int)($parts[3] ?? 0) / 60) * ($parts[1] === '-' ? -1 : 1);

        return ['language' => $language, 'utcOffsetHours' => $hours == (int)$hours ? (int)$hours : $hours];
    }

    /**
     * @param array<array-key, mixed> $payload
     * @return array<array-key, mixed>
     */
    private static function radarAreas(array $payload): array
    {
        if (!is_array($payload['items'] ?? null)) {
            return $payload;
        }
        foreach ($payload['items'] as $index => $item) {
            if (is_array($item) && is_numeric($item['type'] ?? null) && isset(self::AREA_TYPES[(int)$item['type']])) {
                $payload['items'][$index]['type'] = self::AREA_TYPES[(int)$item['type']];
            }
        }

        return $payload;
    }
}
