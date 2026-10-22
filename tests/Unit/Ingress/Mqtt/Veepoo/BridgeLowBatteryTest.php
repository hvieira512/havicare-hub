<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Veepoo;

use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\RecordingHubMqttBridge;
use Tests\Support\Doubles\VeepooFixtures;

/** A pulseira repete o `lowVoltage` em cada leitura: o `low_battery` sai quando ele aparece. */
final class BridgeLowBatteryTest extends TestCase
{
    private const TOPIC = 'havicare-hub/null/0/gw/bef341903987/raw';

    public function testTheLowVoltageFlagRaisesTheEventOnce(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = VeepooFixtures::bridge($mqtt);

        $bridge->handleReceivedMessage(self::TOPIC, self::battery(['VPDeviceIsPercent' => true, 'VPDeviceElectricPercent' => 9, 'VPDeviceElectricTypeIsLowVoltage' => 'lowVoltage']));
        $bridge->handleReceivedMessage(self::TOPIC, self::battery(['VPDeviceIsPercent' => true, 'VPDeviceElectricPercent' => 8, 'VPDeviceElectricTypeIsLowVoltage' => 'lowVoltage']));

        $events = $mqtt->ofType('low_battery', 'events');
        self::assertCount(1, $events);
        self::assertSame(['percent' => 9], $events[0]['payload']['data']);
        self::assertSame(['percent' => 9, 'lowBattery' => true], $mqtt->telemetry[0]['payload']['data']);
    }

    /** O `normal` diz que a bateria não está fraca, e é isso que deixa o alerta voltar a sair. */
    public function testANormalReadingClearsTheFlag(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = VeepooFixtures::bridge($mqtt);

        $bridge->handleReceivedMessage(self::TOPIC, self::battery(['VPDeviceIsPercent' => true, 'VPDeviceElectricPercent' => 9, 'VPDeviceElectricTypeIsLowVoltage' => 'lowVoltage']));
        $bridge->handleReceivedMessage(self::TOPIC, self::battery(['VPDeviceIsPercent' => true, 'VPDeviceElectricPercent' => 80, 'VPDeviceElectricTypeIsLowVoltage' => 'normal']));
        $bridge->handleReceivedMessage(self::TOPIC, self::battery(['VPDeviceIsPercent' => true, 'VPDeviceElectricPercent' => 9, 'VPDeviceElectricTypeIsLowVoltage' => 'lowVoltage']));

        self::assertFalse($mqtt->telemetry[1]['payload']['data']['lowBattery'] ?? null);
        self::assertCount(2, $mqtt->ofType('low_battery', 'events'));
    }

    /** Sem percentagem a tensão não se converte, mas a bateria fraca não depende dela. */
    public function testAGradeReadingStillCarriesTheFlag(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        VeepooFixtures::bridge($mqtt)->handleReceivedMessage(self::TOPIC, self::battery(['VPDeviceIsPercent' => false, 'VPDeviceElectricGrade' => 1, 'VPDeviceElectricTypeIsLowVoltage' => 'lowVoltage']));

        self::assertSame(['lowBattery' => true], $mqtt->telemetry[0]['payload']['data'] ?? null);
        self::assertSame(['low_battery'], array_column($mqtt->events, 'type'));
    }

    /** @param array<string, mixed> $payload */
    private static function battery(array $payload): string
    {
        return VeepooFixtures::message('battery', $payload);
    }
}
