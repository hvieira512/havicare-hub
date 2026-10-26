<?php

declare(strict_types=1);

namespace Tests\Integration\Ingress\Http\Qinglanst;

use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Ingress\Http\Qinglanst\QinglanstCloudCommands;
use Hub\Ingress\Http\Qinglanst\RadarStateAbsorber;
use Tests\Support\Doubles\RecordingHubMqttBridge;
use Tests\Support\MysqlDashboardTestCase;
use Tests\Support\TestInventory;

/** Uma leitura do radar passa a ser o que o hub mostra dele: as configurações e o estado. */
final class RadarStateAbsorberTest extends MysqlDashboardTestCase
{
    private const IMEI = 'AD8A613B0493';

    /** @var array<string, mixed> */
    private const PROPS = [
        'heart_breath_param' => '[24, 90, 8, 50, 0, 5, 1, 10]',
        'postureParams' => '2,3,3',
        'radar_func_ctrl' => '15',
        'signal_intensity' => '-65',
        'accelera' => '-0.93:-0.98:-1.35:1',
        'radar_compile_time' => '2025-06-25 11:50:58',
        'rectangle' => '{-30,-7;19,-7;-30,18;19,18}',
        'declare_area' => '{3,6,-31,1,-25,1,-31,4,-25,4},',
        'declare_area_name' => ['3' => '6_Casa de Banho'],
    ];

    public function testTheSettingsReadBecomeTheHubConfigurationAndTheStateIsPublished(): void
    {
        [$db, $mqtt, $absorber] = $this->absorber();

        $absorber->absorb(self::IMEI, self::PROPS);

        self::assertSame(
            ['fall_alarm' => ['afterSeconds' => 20], 'heart_rate_alert' => ['minBpm' => 50, 'maxBpm' => 90]],
            $db->deviceConfigurations->effectivePayloads(self::IMEI, ['fall_alarm', 'heart_rate_alert']),
        );
        self::assertSame(['connectivity', 'tilt', 'firmware_version'], array_column(array_column($mqtt->telemetry, 'payload'), 'type'));
        self::assertSame('HC2', $mqtt->telemetry[0]['payload']['device']['model']);
    }

    /** O nome que se deu a uma área no hub fica: a API não escreve nomes e a cloud tem o seu. */
    public function testTheAreaNameGivenInTheHubSurvivesARead(): void
    {
        [$db, , $absorber] = $this->absorber();
        $db->deviceConfigurations->recordRead(self::IMEI, 'radar_areas', ['items' => [
            ['key' => 3, 'type' => 'alarm_area', 'name' => 'WC', 'xMinDm' => -31, 'yMinDm' => 1, 'xMaxDm' => -25, 'yMaxDm' => 4],
        ]], 'qinglanst-radar');

        $absorber->absorb(self::IMEI, self::PROPS);

        self::assertSame([3 => 'WC'], $absorber->areaNames(self::IMEI));
        self::assertSame('WC', $db->deviceConfigurations->effectivePayloads(self::IMEI, ['radar_areas'])['radar_areas']['items'][0]['name']);
    }

    /** @return array{0: ApiDataAccess, 1: RecordingHubMqttBridge, 2: RadarStateAbsorber} */
    private function absorber(): array
    {
        $database = $this->createDashboardDatabase();
        TestInventory::register($database->pdo(), self::IMEI, 'Qinglanst', 'HC2', 'radar', 1001, 'hitcare', deviceId: self::IMEI);
        $db = ApiDataAccess::fromDatabase($database);
        $mqtt = new RecordingHubMqttBridge();

        return [$db, $mqtt, new RadarStateAbsorber(
            $db->deviceConfigurations,
            new QinglanstCloudCommands(),
            $db->whitelist,
            $mqtt,
        )];
    }
}
