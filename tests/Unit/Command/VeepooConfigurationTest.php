<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceConfigurationCatalog;
use PHPUnit\Framework\TestCase;

/**
 * As configurações da pulseira que levam valores, e não só um interruptor.
 *
 * O payload de uma pulseira viaja até ao gateway tal e qual, sem construtor de tramas pelo
 * meio. Sem validação aqui, uma janela mal escrita só é apanhada no fim do caminho -- pelo
 * SDK, que a aceita em silêncio e não configura nada.
 */
final class VeepooConfigurationTest extends TestCase
{
    private const PROTOCOL = 'veepoo-ble';

    public function testTheOxygenWindowIsAcceptedWithAValidRange(): void
    {
        self::assertNull(DeviceConfigurationCatalog::validate(
            self::PROTOCOL,
            'blood_oxygen_window',
            ['enabled' => true, 'range' => '22:00-08:00'],
        ));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function badWindows(): array
    {
        return [
            'sem traço' => [['enabled' => true, 'range' => '22:00 08:00']],
            'hora impossível' => [['enabled' => true, 'range' => '25:00-08:00']],
            'minuto impossível' => [['enabled' => true, 'range' => '22:70-08:00']],
            'só um lado' => [['enabled' => true, 'range' => '22:00-']],
            'vazia' => [['enabled' => true, 'range' => '']],
        ];
    }

    /**
     * @dataProvider badWindows
     * @param array<string, mixed> $payload
     */
    public function testAMalformedWindowIsRejected(array $payload): void
    {
        self::assertNotNull(DeviceConfigurationCatalog::validate(
            self::PROTOCOL,
            'blood_oxygen_window',
            $payload,
        ));
    }

    /**
     * O mínimo acima do máximo passa nas validações de cada campo e deixa o alarme impossível
     * de disparar -- é preciso compará-los.
     */
    public function testTheHeartRateThresholdsHaveToLeaveARangeBetweenThem(): void
    {
        self::assertNull(DeviceConfigurationCatalog::validate(
            self::PROTOCOL,
            'heart_rate_alert',
            ['enabled' => true, 'maxBpm' => 150, 'minBpm' => 50],
        ));

        $rejected = [
            'mínimo acima do máximo' => ['maxBpm' => 100, 'minBpm' => 120],
            'mínimo igual ao máximo' => ['maxBpm' => 100, 'minBpm' => 100],
            'máximo impossível' => ['maxBpm' => 300, 'minBpm' => 50],
        ];

        foreach ($rejected as $label => $bad) {
            self::assertNotNull(
                DeviceConfigurationCatalog::validate(self::PROTOCOL, 'heart_rate_alert', ['enabled' => true, ...$bad]),
                "aceitou {$label}",
            );
        }
    }

    /**
     * O que a pulseira deixa configurar e o hub não expõe, por decisão.
     *
     * Alarmes, lembretes, brilho do ecrã e unidades existem no aparelho e não alteram uma
     * leitura -- é comportamento de relógio de pulso e não de sensor.
     */
    public function testWhatDoesNotChangeAMeasurementIsNotOffered(): void
    {
        foreach (['reminder_water', 'alarm_clock', 'raise_to_wake', 'metric_units'] as $key) {
            self::assertNotNull(
                DeviceConfigurationCatalog::validate(self::PROTOCOL, $key, ['enabled' => true]),
                "`{$key}` não devia ser configurável",
            );
        }
    }

    /**
     * O tom de pele regula a potência do LED do sensor ótico, e um valor fora da escala do
     * aparelho não é um ajuste pior -- é um byte que ele não sabe interpretar.
     */
    public function testTheSkinToneLevelIsLimitedToTheBandScale(): void
    {
        self::assertNull(DeviceConfigurationCatalog::validate(self::PROTOCOL, 'skin_tone', ['level' => 3]));
        self::assertNotNull(DeviceConfigurationCatalog::validate(self::PROTOCOL, 'skin_tone', ['level' => 7]));
        self::assertNotNull(DeviceConfigurationCatalog::validate(self::PROTOCOL, 'skin_tone', ['level' => 0]));
    }

    /**
     * A informação pessoal entra no cálculo das calorias e da composição corporal, e por
     * isso um valor absurdo não fica por um número feio no ecrã: contamina telemetria.
     */
    public function testPersonalInformationIsBoundedToPlausibleHumanValues(): void
    {
        $ok = [
            'heightCm' => 175, 'weightKg' => 72, 'age' => 34, 'sex' => 'male',
            'stepGoal' => 8000, 'sleepGoalMinutes' => 480,
        ];
        self::assertNull(DeviceConfigurationCatalog::validate(self::PROTOCOL, 'personal_info', $ok));

        foreach ([['heightCm' => 0], ['weightKg' => 400], ['age' => 200], ['sex' => 'yes']] as $bad) {
            self::assertNotNull(
                DeviceConfigurationCatalog::validate(self::PROTOCOL, 'personal_info', [...$ok, ...$bad]),
                'aceitou ' . json_encode($bad),
            );
        }
    }
}
