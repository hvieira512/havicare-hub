<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Qinglanst\Cloud;

use Hub\Ingress\Mqtt\Qinglanst\Cloud\QinglanstCloudCommands;
use PHPUnit\Framework\TestCase;

/** Mapeia as configurações do hub para os campos do `deviceProp` e para os quatro pedidos de escrita da cloud. */
final class QinglanstCloudCommandsTest extends TestCase
{
    private const UID = 'AD8A613B0493';

    /** @return array<string, mixed> */
    private static function props(): array
    {
        return [
            'radar_install_style' => '0',
            'accelera' => '-0.93:-0.98:-1.35:1:-0.9:0.9',
            'heart_breath_param' => '[24, 90, 8, 50, 0, 5, 1, 10, 0, 0, 0, 0, 0, 0, 0, 0]',
            'postureParams' => '2,3,3',
            'signal_intensity' => '-65',
            'suspected_fall_time' => '2',
            'leaveDetectionRange' => '20:00-08:00',
            'radar_func_ctrl' => '15',
            'declare_area_name' => ['5_Bed', '4_Door', '1_Mesa', '6_Casa de Banho'],
            'radar_install_height' => '29',
            'suddenDeathSwitch' => '0',
            'app_compile_time' => '2026-07-20 21:02:16',
            'rectangle' => '{-30,-7;19,-7;-30,18;19,18}',
            'radar_compile_time' => '2025-06-25 11:50:58',
            'declare_area' => '{0,5,-4,-6,6,-6,-4,13,6,13},{1,4,17,15,20,15,17,18,20,18},{2,1,7,-6,12,-6,7,-1,12,-1},{3,6,-31,1,-25,1,-31,4,-25,4},',
        ];
    }

    public function testTheRadarPropsBecomeTheHubConfigurations(): void
    {
        $configurations = (new QinglanstCloudCommands())->configurations(self::props());

        self::assertSame(['sensitivity' => 1], $configurations['vital_sign_sensitivity']);
        self::assertSame(['minPerMinute' => 8, 'maxPerMinute' => 24], $configurations['breath_rate_alert']);
        self::assertSame(['minBpm' => 50, 'maxBpm' => 90], $configurations['heart_rate_alert']);
        self::assertSame(['enabled' => true, 'afterMinutes' => 5], $configurations['weak_vital_signs_alarm']);
        self::assertSame(['afterSeconds' => 20], $configurations['fall_alarm']);
        self::assertSame(['enabled' => true, 'afterSeconds' => 30], $configurations['sitting_on_floor_alarm']);
        self::assertSame(['mode' => 'full_function'], $configurations['working_mode']);
        self::assertSame(['mounting' => 'ceiling', 'heightCm' => 290], $configurations['radar_installation']);
        self::assertSame(['leftM' => 3.0, 'rightM' => 1.9, 'frontM' => 1.8, 'backM' => 0.7], $configurations['detection_boundary']);
        self::assertSame(
            ['key' => 3, 'type' => 'alarm_area', 'name' => 'Casa de Banho', 'xMinDm' => -31, 'yMinDm' => 1, 'xMaxDm' => -25, 'yMaxDm' => 4],
            $configurations['radar_areas']['items'][3],
        );
    }

    /** O sinal, a inclinação e o firmware saem como telemetria, e não como configuração. */
    public function testTheRadarStateBecomesTelemetry(): void
    {
        $telemetry = (new QinglanstCloudCommands())->telemetry(self::props());

        self::assertSame(['interface' => 'wifi', 'signalStrengthDbm' => -65], $telemetry['connectivity']);
        self::assertSame(['xDegrees' => -0.93, 'yDegrees' => -0.98, 'zDegrees' => -1.35, 'factoryCalibrated' => true], $telemetry['tilt']);
        self::assertSame(['version' => '2025-06-25 11:50:58', 'communicationVersion' => '2026-07-20 21:02:16'], $telemetry['firmware_version']);
    }

    /** Os sete campos vão sempre juntos: um que falte volta ao valor de fábrica. */
    public function testAThresholdChangeCarriesTheOtherSixAsTheRadarHasThem(): void
    {
        $requests = (new QinglanstCloudCommands())->requests('heart_rate_alert', ['minBpm' => 45, 'maxBpm' => 110], self::props(), self::UID);

        self::assertSame([[
            'path' => '/thirdparty/v2/threshold',
            'body' => [
                'uid' => self::UID,
                'upperBreathe' => 24,
                'upperHeartRate' => 110,
                'lowerBreathe' => 8,
                'lowerHeartRate' => 45,
                'intensiveCare' => 0,
                'suddenDuration' => 5,
                'sensitivity' => 1,
            ],
        ]], $requests);
    }

    /** O interruptor e o tempo dos sinais fracos vivem em pedidos diferentes. */
    public function testWeakVitalSignsSendTheSwitchWithTheModeAndTheTimeWithTheThresholds(): void
    {
        $requests = (new QinglanstCloudCommands())->requests('weak_vital_signs_alarm', ['enabled' => false, 'afterMinutes' => 12], self::props(), self::UID);

        self::assertSame(['uid' => self::UID, 'scene' => '1', 'suddenDeathSwitch' => '1'], $requests[0]['body']);
        self::assertSame('/thirdparty/v2/prop', $requests[0]['path']);
        self::assertSame(12, $requests[1]['body']['suddenDuration']);
        self::assertSame(1, $requests[1]['body']['sensitivity']);
    }

    /** A queda e o sentado no chão partilham a mesma cadeia: cada um mexe só na sua parte. */
    public function testThePostureStringKeepsWhatTheOtherSettingOwns(): void
    {
        $commands = new QinglanstCloudCommands();

        self::assertSame(
            [['path' => '/thirdparty/v2/prop', 'body' => ['uid' => self::UID, 'postureParams' => '6,3,3']]],
            $commands->requests('fall_alarm', ['afterSeconds' => 60], self::props(), self::UID),
        );
        self::assertSame(
            [['path' => '/thirdparty/v2/prop', 'body' => ['uid' => self::UID, 'postureParams' => '2,2,10']]],
            $commands->requests('sitting_on_floor_alarm', ['enabled' => false, 'afterSeconds' => 100], self::props(), self::UID),
        );
    }

    public function testModeInstallationAndBoundaryUseTheApiUnits(): void
    {
        $commands = new QinglanstCloudCommands();

        self::assertSame(
            ['uid' => self::UID, 'scene' => '4'],
            $commands->requests('working_mode', ['mode' => 'sleep_monitoring'], self::props(), self::UID)[0]['body'],
        );
        self::assertSame(
            ['uid' => self::UID, 'installType' => '1', 'height' => 18],
            $commands->requests('radar_installation', ['mounting' => 'wall', 'heightCm' => 180], self::props(), self::UID)[0]['body'],
        );
        self::assertSame(
            ['path' => '/thirdparty/v2/border', 'body' => ['uid' => self::UID, 'lowLeft' => '-19,-20', 'lowRight' => '30,-20', 'upLeft' => '-19,7', 'upRight' => '30,7']],
            $commands->requests('detection_boundary', ['leftM' => 1.9, 'rightM' => 3, 'frontM' => 0.7, 'backM' => 2], self::props(), self::UID)[0],
        );
    }

    /** A medição automática começa com −127 nos quatro vértices e pára com 127. */
    public function testMeasuringTheBoundaryStartsAndStops(): void
    {
        $commands = new QinglanstCloudCommands();

        self::assertSame('-127,-127', $commands->requests('measure_boundary', ['enabled' => true], self::props(), self::UID)[0]['body']['upRight']);
        self::assertSame('127,127', $commands->requests('measure_boundary', ['enabled' => false], self::props(), self::UID)[0]['body']['lowLeft']);
    }

    /** Só sai o que mudou: uma área alterada, uma nova, e a que desapareceu vai com o tipo 0. */
    public function testOnlyTheAreasThatChangedAreSent(): void
    {
        $requests = (new QinglanstCloudCommands())->requests('radar_areas', ['items' => [
            ['key' => 0, 'type' => 'monitoring_bed', 'name' => 'Cama', 'xMinDm' => -4, 'yMinDm' => -6, 'xMaxDm' => 6, 'yMaxDm' => 13],
            ['key' => 1, 'type' => 'door', 'name' => 'Porta', 'xMinDm' => 16, 'yMinDm' => 15, 'xMaxDm' => 19, 'yMaxDm' => 18],
            ['key' => 2, 'type' => 'custom', 'name' => 'Mesa', 'xMinDm' => 7, 'yMinDm' => -6, 'xMaxDm' => 12, 'yMaxDm' => -1],
            ['key' => 4, 'type' => 'interference', 'name' => 'Ventoinha', 'xMinDm' => 10, 'yMinDm' => 10, 'xMaxDm' => 14, 'yMaxDm' => 14],
        ]], self::props(), self::UID);

        self::assertSame([
            ['path' => '/thirdparty/v2/coords', 'body' => ['uid' => self::UID, 'coordKey' => '1', 'type' => 4, 'lowLeft' => '16,15', 'lowRight' => '19,15', 'upLeft' => '16,18', 'upRight' => '19,18']],
            ['path' => '/thirdparty/v2/coords', 'body' => ['uid' => self::UID, 'coordKey' => '4', 'type' => 3, 'lowLeft' => '10,10', 'lowRight' => '14,10', 'upLeft' => '10,14', 'upRight' => '14,14']],
            ['path' => '/thirdparty/v2/coords', 'body' => ['uid' => self::UID, 'coordKey' => '3', 'type' => 0]],
        ], $requests);
    }
}
