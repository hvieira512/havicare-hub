<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Migration\PublicConfigurationShape;
use PDO;
use Tests\Support\MysqlDashboardTestCase;

/** As configurações guardadas passam à forma pública, sem nomes de fornecedor nem códigos crus. */
final class PublicConfigurationShapeTest extends MysqlDashboardTestCase
{
    private const IMEI = '868705080300697';

    /** @return iterable<string, array{string, string, string, array<string, mixed>, array<string, mixed>}> */
    public static function storedRows(): iterable
    {
        yield 'wonlex switch' => ['wonlex-json', 'fall_detection', 'wonlexFallWarnSwitch', ['switchState' => true], ['enabled' => true]];
        yield 'wonlex location interval' => ['wonlex-json', 'location_reporting_interval', 'locationInterval', ['intervalTime' => 300], ['intervalSeconds' => 300]];
        yield 'wonlex measurement interval' => ['wonlex-json', 'heart_rate_measurement_interval', 'wonlexHeartRateInterval', ['interval' => 15], ['intervalMinutes' => 15]];
        yield 'wonlex battery threshold' => ['wonlex-json', 'low_battery_alert', 'wonlexLowPower', ['Battery' => 20], ['thresholdPercent' => 20]];
        yield 'wonlex sleep' => [
            'wonlex-json', 'sleep_monitoring', 'wonlexSleepIntervalOrSwitch',
            ['switchState' => true, 'sleepStartTime' => '220000', 'sleepEndTime' => '073000', 'sleepTarget' => 480],
            ['enabled' => true, 'startTime' => '22:00', 'endTime' => '07:30', 'targetMinutes' => 480],
        ];
        yield 'wonlex oxygen' => ['wonlex-json', 'blood_oxygen_alert', 'wonlexBloodOxygenWarn', ['switchState' => false, 'reminderValue' => 90], ['enabled' => false, 'thresholdPercent' => 90]];
        yield 'wonlex temperature' => ['wonlex-json', 'temperature_high_alert', 'wonlexTemperatureExceedRemind', ['switchState' => true, 'RemindValue' => 38.5], ['enabled' => true, 'thresholdCelsius' => 38.5]];
        yield 'wonlex blood pressure' => [
            'wonlex-json', 'blood_pressure_alert', 'wonlexBPEarlyWarning',
            ['switchState' => true, 'hpWarn' => 135, 'LPWarn' => 90],
            ['enabled' => true, 'systolicMmHg' => 135, 'diastolicMmHg' => 90],
        ];
        yield 'wonlex heart rate' => [
            'wonlex-json', 'heart_rate_high_alert', 'wonlexHeartRateHighRemind',
            ['switchState' => true, 'remindValue' => 120, 'exerciseSwitchState' => false, 'exerciseHRMin' => 100, 'exerciseHRMax' => 140, 'exerciseRemindValue' => 150],
            ['enabled' => true, 'thresholdBpm' => 120, 'exerciseEnabled' => false, 'exerciseMinBpm' => 100, 'exerciseMaxBpm' => 140, 'exerciseThresholdBpm' => 150],
        ];
        yield 'four-p-touch sound profile' => ['four-p-touch', 'sound_profile', 'profile', ['mode' => 3], ['mode' => 'vibrate']];
        yield 'four-p-touch language' => ['four-p-touch', 'language_timezone', 'languageTimezone', ['language' => 3, 'timeZone' => '1'], ['language' => 'pt', 'utcOffsetHours' => 1]];
        yield 'four-p-touch language with a signed offset' => ['four-p-touch', 'language_timezone', 'languageTimezone', ['language' => 0, 'timeZone' => '-03:30'], ['language' => 'en', 'utcOffsetHours' => -3.5]];
        yield 'radar area types' => [
            'qinglanst-radar', 'radar_areas', 'radar_areas',
            ['items' => [['name' => 'Cama', 'type' => 2], ['name' => 'Porta', 'type' => 4], ['name' => 'Sofá', 'type' => 7]]],
            ['items' => [['name' => 'Cama', 'type' => 'bed'], ['name' => 'Porta', 'type' => 'door'], ['name' => 'Sofá', 'type' => 'furniture']]],
        ];
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('storedRows')]
    public function testAStoredRowMovesToThePublicShape(string $protocol, string $configKey, string $nativeKey, array $old, array $new): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $this->storeRow($pdo, $configKey, $nativeKey, $protocol, $old);
        $this->storeChange($pdo, $configKey, $old);

        (new PublicConfigurationShape())->up($pdo);
        (new PublicConfigurationShape())->up($pdo);

        self::assertSame($new, $this->desired($pdo, 'device_configurations', $configKey));
        self::assertSame($new, $this->desired($pdo, 'device_configuration_changes', $configKey));
        self::assertSame($new, $this->decoded($pdo, "SELECT effective_payload FROM device_configuration_changes WHERE config_key = '{$configKey}'"));
    }

    /** Os números SOS que a Wonlex funde na lista telefónica chamam-se `numbers`, como em todo o lado. */
    public function testTheWonlexPhonebookChangeNamesItsSosNumbers(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $this->storeChange($pdo, 'phonebook', ['contacts' => [['name' => 'Ana', 'phone' => '+351911']], 'sosNumbers' => ['+351911']]);

        (new PublicConfigurationShape())->up($pdo);

        self::assertSame(
            ['contacts' => [['name' => 'Ana', 'phone' => '+351911']], 'numbers' => ['+351911']],
            $this->desired($pdo, 'device_configuration_changes', 'phonebook'),
        );
    }

    /** Uma resposta guardada debaixo do tipo nativo não tem configuração pública a que pertença. */
    public function testRowsKeyedByANativeTypeAreDeleted(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        foreach (['AP40', 'AP43', 'CONFIG', 'upGetDevConfig'] as $nativeType) {
            $this->storeRow($pdo, $nativeType, $nativeType, 'wonlex-json', []);
            $this->storeChange($pdo, $nativeType, []);
        }
        $this->storeRow($pdo, 'fall_detection', 'wonlexFallWarnSwitch', 'wonlex-json', ['enabled' => true]);

        (new PublicConfigurationShape())->up($pdo);

        self::assertSame(['fall_detection'], $pdo->query('SELECT config_key FROM device_configurations')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM device_configuration_changes')->fetchColumn());
    }

    /** A chave nativa entra na chave primária: o despertador da Wonlex passa a `alarmClock`. */
    public function testTheWonlexAlarmClockMovesToItsNativeKey(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $this->storeRow($pdo, 'alarm_clock', 'alarm_clock', 'wonlex-json', ['items' => []]);

        (new PublicConfigurationShape())->up($pdo);

        self::assertSame(['alarmClock'], $pdo->query("SELECT native_key FROM device_configurations WHERE config_key = 'alarm_clock'")->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param array<string, mixed> $payload */
    private function storeRow(PDO $pdo, string $configKey, string $nativeKey, string $protocol, array $payload): void
    {
        $pdo->prepare("
            INSERT INTO device_configurations (imei, config_key, native_key, protocol, desired_payload, reported_payload)
            VALUES (?, ?, ?, ?, ?, '{}')
        ")->execute([self::IMEI, $configKey, $nativeKey, $protocol, json_encode($payload === [] ? new \stdClass() : $payload)]);
    }

    /** @param array<string, mixed> $payload */
    private function storeChange(PDO $pdo, string $configKey, array $payload): void
    {
        $encoded = json_encode($payload === [] ? new \stdClass() : $payload);
        $pdo->prepare("
            INSERT INTO device_configuration_changes
                (change_id, imei, config_key, desired_revision, desired_payload, effective_payload, created_at, updated_at)
            VALUES (?, ?, ?, 1, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
        ")->execute([bin2hex(random_bytes(8)), self::IMEI, $configKey, $encoded, $encoded]);
    }

    /** @return array<string, mixed>|null */
    private function desired(PDO $pdo, string $table, string $configKey): ?array
    {
        return $this->decoded($pdo, "SELECT desired_payload FROM {$table} WHERE config_key = '{$configKey}'");
    }

    /** @return array<string, mixed>|null */
    private function decoded(PDO $pdo, string $sql): ?array
    {
        $value = $pdo->query($sql)->fetchColumn();

        return is_string($value) ? json_decode($value, true) : null;
    }
}
