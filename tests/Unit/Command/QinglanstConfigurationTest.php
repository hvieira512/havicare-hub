<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceConfigurationCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** As configurações do radar, com os limites da API Cloud2cloud da Qinglanst (secções 5.3.2 a 5.3.5). */
final class QinglanstConfigurationTest extends TestCase
{
    private const PROTOCOL = 'qinglanst-radar';

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function accepted(): iterable
    {
        yield 'sensibilidade' => ['vital_sign_sensitivity', ['sensitivity' => 1]];
        yield 'respiração' => ['breath_rate_alert', ['minPerMinute' => 8, 'maxPerMinute' => 24]];
        yield 'frequência cardíaca' => ['heart_rate_alert', ['minBpm' => 45, 'maxBpm' => 110]];
        yield 'sinais fracos' => ['weak_vital_signs_alarm', ['enabled' => true, 'afterMinutes' => 10]];
        yield 'queda' => ['fall_alarm', ['afterSeconds' => 60]];
        yield 'sentado no chão' => ['sitting_on_floor_alarm', ['enabled' => false, 'afterSeconds' => 100]];
        yield 'modo' => ['working_mode', ['mode' => 'sleep_monitoring']];
        yield 'instalação' => ['radar_installation', ['mounting' => 'wall', 'heightCm' => 150]];
        yield 'limites' => ['detection_boundary', ['leftM' => 1.9, 'rightM' => 3, 'frontM' => 0.7, 'backM' => 2]];
        yield 'áreas' => ['radar_areas', ['items' => [
            ['key' => 0, 'type' => 5, 'name' => 'Cama', 'xMinDm' => -4, 'yMinDm' => -6, 'xMaxDm' => 6, 'yMaxDm' => 13],
        ]]];
        yield 'medir os limites' => ['measure_boundary', ['enabled' => true]];
        yield 'ler do radar' => ['read_device_state', []];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('accepted')]
    public function testAValueInsideTheApiLimitsIsAccepted(string $key, array $payload): void
    {
        self::assertNull(DeviceConfigurationCatalog::validate(self::PROTOCOL, $key, $payload));
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function rejected(): iterable
    {
        yield 'sensibilidade acima de 99' => ['vital_sign_sensitivity', ['sensitivity' => 100]];
        yield 'baixo igual ao alto' => ['breath_rate_alert', ['minPerMinute' => 24, 'maxPerMinute' => 24]];
        yield 'FC acima de 255' => ['heart_rate_alert', ['minBpm' => 50, 'maxBpm' => 256]];
        yield 'queda fora dos blocos de 10 s' => ['fall_alarm', ['afterSeconds' => 65]];
        yield 'queda acima de 5 min' => ['fall_alarm', ['afterSeconds' => 310]];
        yield 'sentado abaixo de 10 s' => ['sitting_on_floor_alarm', ['enabled' => true, 'afterSeconds' => 0]];
        yield 'modo desconhecido' => ['working_mode', ['mode' => 'bed']];
        yield 'altura abaixo de 1,5 m' => ['radar_installation', ['mounting' => 'ceiling', 'heightCm' => 140]];
        yield 'altura fora dos decímetros' => ['radar_installation', ['mounting' => 'ceiling', 'heightCm' => 285]];
        yield 'limite acima de 4 m' => ['detection_boundary', ['leftM' => 1, 'rightM' => 1, 'frontM' => 4.1, 'backM' => 0]];
        yield 'área com o mesmo número' => ['radar_areas', ['items' => [
            ['key' => 1, 'type' => 4, 'name' => 'Porta', 'xMinDm' => 0, 'yMinDm' => 0, 'xMaxDm' => 3, 'yMaxDm' => 3],
            ['key' => 1, 'type' => 6, 'name' => 'Alarme', 'xMinDm' => 5, 'yMinDm' => 5, 'xMaxDm' => 8, 'yMaxDm' => 8],
        ]]];
        yield 'área sem tamanho' => ['radar_areas', ['items' => [
            ['key' => 2, 'type' => 1, 'name' => 'Mesa', 'xMinDm' => 3, 'yMinDm' => 0, 'xMaxDm' => 3, 'yMaxDm' => 5],
        ]]];
        yield 'área número 16' => ['radar_areas', ['items' => [
            ['key' => 16, 'type' => 1, 'name' => 'Mesa', 'xMinDm' => 0, 'yMinDm' => 0, 'xMaxDm' => 3, 'yMaxDm' => 5],
        ]]];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('rejected')]
    public function testAValueOutsideTheApiLimitsIsRefused(string $key, array $payload): void
    {
        self::assertNotNull(DeviceConfigurationCatalog::validate(self::PROTOCOL, $key, $payload));
    }

    /** As três secções que o hub já usa nos outros aparelhos, sem secções novas. */
    public function testTheRadarSettingsLiveInTheExistingSections(): void
    {
        $categories = array_unique(array_column(DeviceConfigurationCatalog::configsForProtocol(self::PROTOCOL), 'category'));
        sort($categories);

        self::assertSame(['alarms', 'health', 'system'], $categories);
    }
}
