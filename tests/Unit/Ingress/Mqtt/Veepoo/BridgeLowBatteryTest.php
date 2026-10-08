<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Veepoo;

use Hub\Ingress\Mqtt\Veepoo\VeepooBridge;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\ArrayObservationStateStore;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/** A pulseira repete o `lowVoltage` em cada leitura: o `low_battery` sai quando ele aparece. */
final class BridgeLowBatteryTest extends TestCase
{
    private const GATEWAY = 'bef341903987';
    private const BRACELET = '9f69c4866e6c';
    private const TOPIC = 'havicare-hub/null/0/gw/bef341903987/raw';

    public function testTheLowVoltageFlagRaisesTheEventOnce(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        $bridge->handleReceivedMessage(self::TOPIC, self::battery(['VPDeviceIsPercent' => true, 'VPDeviceElectricPercent' => 9, 'VPDeviceElectricTypeIsLowVoltage' => 'lowVoltage']));
        $bridge->handleReceivedMessage(self::TOPIC, self::battery(['VPDeviceIsPercent' => true, 'VPDeviceElectricPercent' => 8, 'VPDeviceElectricTypeIsLowVoltage' => 'lowVoltage']));

        $events = array_values(array_filter($mqtt->events, static fn (array $event): bool => $event['type'] === 'low_battery'));
        self::assertCount(1, $events);
        self::assertSame(['percent' => 9], $events[0]['payload']['data']);
        self::assertSame(['percent' => 9, 'lowBattery' => true], $mqtt->telemetry[0]['payload']['data']);
    }

    /** O `normal` diz que a bateria não está fraca, e é isso que deixa o alerta voltar a sair. */
    public function testANormalReadingClearsTheFlag(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        $bridge->handleReceivedMessage(self::TOPIC, self::battery(['VPDeviceIsPercent' => true, 'VPDeviceElectricPercent' => 9, 'VPDeviceElectricTypeIsLowVoltage' => 'lowVoltage']));
        $bridge->handleReceivedMessage(self::TOPIC, self::battery(['VPDeviceIsPercent' => true, 'VPDeviceElectricPercent' => 80, 'VPDeviceElectricTypeIsLowVoltage' => 'normal']));
        $bridge->handleReceivedMessage(self::TOPIC, self::battery(['VPDeviceIsPercent' => true, 'VPDeviceElectricPercent' => 9, 'VPDeviceElectricTypeIsLowVoltage' => 'lowVoltage']));

        self::assertFalse($mqtt->telemetry[1]['payload']['data']['lowBattery'] ?? null);
        self::assertCount(2, array_filter($mqtt->events, static fn (array $event): bool => $event['type'] === 'low_battery'));
    }

    /** Sem percentagem a tensão não se converte, mas a bateria fraca não depende dela. */
    public function testAGradeReadingStillCarriesTheFlag(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $this->bridge($mqtt)->handleReceivedMessage(self::TOPIC, self::battery(['VPDeviceIsPercent' => false, 'VPDeviceElectricGrade' => 1, 'VPDeviceElectricTypeIsLowVoltage' => 'lowVoltage']));

        self::assertSame(['lowBattery' => true], $mqtt->telemetry[0]['payload']['data'] ?? null);
        self::assertSame(['low_battery'], array_column($mqtt->events, 'type'));
    }

    /** @param array<string, mixed> $payload */
    private static function battery(array $payload): string
    {
        return json_encode([
            'source' => 'veepoo-node',
            'kind' => 'battery',
            'device' => ['mac' => self::BRACELET],
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR);
    }

    private function bridge(RecordingHubMqttBridge $mqtt): VeepooBridge
    {
        return new VeepooBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                self::GATEWAY => IngressFixtures::device('Havicare', 'Veepoo Gateway', 'gateway'),
                self::BRACELET => IngressFixtures::device('Wonlex', 'MF91', 'bracelet'),
            ]),
            $mqtt,
            IngressFixtures::links(true),
            null,
            new ArrayObservationStateStore(),
            'havicare-hub/null/0/gw/+/raw',
        );
    }
}
