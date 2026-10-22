<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Migration\VivistarWorkingModeNames;
use PDO;
use Tests\Support\MysqlDashboardTestCase;

/** O modo de funcionamento guardado de uma Vivistar passa do número do fabricante ao nome. */
final class VivistarWorkingModeNamesTest extends MysqlDashboardTestCase
{
    private const IMEI = '861265061009822';

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>}> */
    public static function storedModes(): iterable
    {
        yield 'normal' => [['mode' => 1], ['mode' => 'normal']];
        yield 'power saving' => [['mode' => 2], ['mode' => 'power_saving']];
        yield 'emergency' => [['mode' => 3], ['mode' => 'emergency']];
        yield 'custom' => [['mode' => 8, 'intervalSeconds' => 60, 'gpsEnabled' => true], ['mode' => 'custom', 'intervalSeconds' => 60, 'gpsEnabled' => true]];
        yield 'a number in a string' => [['mode' => '2'], ['mode' => 'power_saving']];
        yield 'already a name' => [['mode' => 'custom', 'intervalSeconds' => 30], ['mode' => 'custom', 'intervalSeconds' => 30]];
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('storedModes')]
    public function testAStoredModeTakesItsName(array $old, array $new): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $encoded = json_encode($old);
        $pdo->prepare("
            INSERT INTO device_configurations (imei, config_key, native_key, protocol, desired_payload, reported_payload)
            VALUES (?, 'working_mode', 'workingMode', 'vivistar-iw', ?, '{}')
        ")->execute([self::IMEI, $encoded]);
        $pdo->prepare("
            INSERT INTO device_configuration_changes
                (change_id, imei, config_key, desired_revision, desired_payload, effective_payload, created_at, updated_at)
            VALUES ('c1', ?, 'working_mode', 1, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
        ")->execute([self::IMEI, $encoded, $encoded]);

        (new VivistarWorkingModeNames())->up($pdo);
        (new VivistarWorkingModeNames())->up($pdo);

        self::assertSame($new, $this->decoded($pdo, 'SELECT desired_payload FROM device_configurations'));
        self::assertSame($new, $this->decoded($pdo, 'SELECT desired_payload FROM device_configuration_changes'));
        self::assertSame($new, $this->decoded($pdo, 'SELECT effective_payload FROM device_configuration_changes'));
    }

    /** O radar também tem `working_mode`, e o dele já é nome. */
    public function testARadarModeIsLeftAlone(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $pdo->exec("
            INSERT INTO device_configurations (imei, config_key, native_key, protocol, desired_payload, reported_payload)
            VALUES ('radar-1', 'working_mode', 'working_mode', 'qinglanst-radar', '{\"mode\":\"fall_detection\"}', '{}')
        ");

        (new VivistarWorkingModeNames())->up($pdo);

        self::assertSame(['mode' => 'fall_detection'], $this->decoded($pdo, 'SELECT desired_payload FROM device_configurations'));
    }

    /** @return array<string, mixed>|null */
    private function decoded(PDO $pdo, string $sql): ?array
    {
        $value = $pdo->query($sql)->fetchColumn();

        return is_string($value) ? json_decode($value, true) : null;
    }
}
