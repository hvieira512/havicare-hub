<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt;

use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Ingress\Mqtt\Moko\MokoBridge;
use Hub\Ingress\Mqtt\Ncs\NcsBridge;
use Hub\Ingress\Mqtt\Veepoo\MeasurementNormalizer;
use Hub\Ingress\Mqtt\Qinglanst\QinglanstBridge;
use Hub\Ingress\Mqtt\Veepoo\VeepooBridge;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\ArrayObservationStateStore;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/** O canal de cada capacidade no MQTT contra o `isEvent` do catálogo, nas quatro pontes. */
final class CapabilityChannelTest extends TestCase
{
    private const GATEWAY = 'd48c49f7909c';
    private const BUTTON = 'fbd87c59ba8b';
    private const DIAPER_SENSOR = 'eec5000202f9';
    private const RADAR = 'radar-canonical-1';
    private const RADAR_UID = 'radar-topic-uid';
    private const NCS = 'gw-001';
    private const VEEPOO_GATEWAY = 'bef341903987';
    private const BRACELET = '9f69c4866e6c';

    /** A queda do radar é o caso concreto: o normalizador põe-na nos eventos e a ponte tem de a publicar lá. */
    public function testTheRadarPublishesEachCapabilityOnTheChannelTheCatalogDeclares(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = new QinglanstBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                self::RADAR => IngressFixtures::radar() + ['deviceId' => self::RADAR_UID],
            ]),
            $mqtt,
        );

        $bridge->handleReceivedMessage(self::radarTopic(), self::radarMessage('position', self::person(postureCode: 5)));
        $bridge->handleReceivedMessage(self::radarTopic(), self::radarMessage('heartbreath', self::heartBreath()));

        $seen = $this->assertChannelsMatchTheCatalog($mqtt);

        self::assertContains('fall', $seen['events']);
        self::assertContains('presence', $seen['telemetry']);
    }

    public function testTheMokoRelayPublishesEachCapabilityOnTheChannelTheCatalogDeclares(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = new MokoBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                self::GATEWAY => IngressFixtures::gateway('MKGW3'),
                self::BUTTON => IngressFixtures::bracelet('W6B'),
            ]),
            $mqtt,
            IngressFixtures::links(),
            new ArrayObservationStateStore(),
        );

        // O primeiro avistamento é a linha de base do contador; o segundo é o toque.
        $bridge->handleReceivedMessage(self::gatewayTopic(), self::buttonScan(69));
        $bridge->handleReceivedMessage(self::gatewayTopic(), self::buttonScan(70));

        $seen = $this->assertChannelsMatchTheCatalog($mqtt);

        self::assertContains('help_call', $seen['events']);
        self::assertContains('battery', $seen['telemetry']);
        self::assertContains('proximity', $seen['telemetry']);
    }

    public function testTheDiaperSensorPublishesEachCapabilityOnTheChannelTheCatalogDeclares(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = new MokoBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                self::GATEWAY => IngressFixtures::gateway('MKGW3'),
                self::DIAPER_SENSOR => IngressFixtures::diaperSensor(),
            ]),
            $mqtt,
            IngressFixtures::links(),
            new ArrayObservationStateStore(),
        );

        $bridge->handleReceivedMessage(self::gatewayTopic(), self::diaperScan());

        $seen = $this->assertChannelsMatchTheCatalog($mqtt);

        self::assertContains('change_required', $seen['events']);
        self::assertContains('diaper_condition', $seen['telemetry']);
    }

    public function testTheNcsPublishesEachCapabilityOnTheChannelTheCatalogDeclares(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = new NcsBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([self::NCS => IngressFixtures::device('Voerka', 'W812', 'ncs')]),
            $mqtt,
        );

        $bridge->handleReceivedMessage('/voerka/1001/devices/' . self::NCS . '/events', json_encode([
            'from' => self::NCS,
            'type' => 6,
            'timestamp' => 372315009,
            'payload' => ['id' => '482929', 'key' => '8', 'code' => 4000, 'result' => 1],
        ], JSON_THROW_ON_ERROR));

        $seen = $this->assertChannelsMatchTheCatalog($mqtt);

        self::assertContains('help_call', $seen['events']);
    }

    public function testTheVeepooBraceletPublishesEachCapabilityOnTheChannelTheCatalogDeclares(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = new VeepooBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                self::VEEPOO_GATEWAY => IngressFixtures::device('Havicare', 'Veepoo Gateway', 'gateway'),
                self::BRACELET => IngressFixtures::device('Wonlex', 'MF91', 'bracelet'),
            ]),
            $mqtt,
            IngressFixtures::links(),
            null,
            new ArrayObservationStateStore(),
            'havicare-hub/null/0/gw/+/raw',
        );

        $bridge->handleReceivedMessage(self::veepooTopic(), json_encode([
            'source' => 'veepoo-node',
            'kind' => 'session',
            'device' => ['mac' => self::BRACELET, 'firmware' => '02.73.01.00-5966'],
            'payload' => ['authenticated' => true],
        ], JSON_THROW_ON_ERROR));

        foreach ([['sdkType' => 51, 'heartRate' => 74], ['sdkType' => 31, 'bloodOxygen' => 97]] as $measurement) {
            $bridge->handleReceivedMessage(self::veepooTopic(), json_encode([
                'source' => 'veepoo-node',
                'kind' => 'measurement',
                'device' => ['mac' => self::BRACELET],
                'payload' => $measurement,
            ], JSON_THROW_ON_ERROR));

            // Uma medição a assentar só sai quando o pedido que a mandou fazer se fecha.
            $operation = MeasurementNormalizer::operationForSdkType((int)$measurement['sdkType']);
            if ($operation !== null) {
                $bridge->handleReceivedMessage(self::veepooTopic(), json_encode([
                    'source' => 'veepoo-node',
                    'kind' => 'command_result',
                    'device' => ['mac' => self::BRACELET],
                    'payload' => ['dedupeKey' => $operation . '-key', 'operation' => $operation],
                ], JSON_THROW_ON_ERROR));
            }
        }

        $seen = $this->assertChannelsMatchTheCatalog($mqtt);

        self::assertContains('heart_rate', $seen['telemetry']);
        self::assertContains('blood_oxygen', $seen['telemetry']);
    }

    /**
     * As capacidades publicadas, por canal, depois de conferir cada uma contra o catálogo.
     *
     * O que o catálogo não declara é do ciclo de vida do hub -- `device.connected` e afins --
     * e não tem capacidade com que ser comparado.
     *
     * @return array{events: list<string>, telemetry: list<string>}
     */
    private function assertChannelsMatchTheCatalog(RecordingHubMqttBridge $mqtt): array
    {
        $seen = ['events' => [], 'telemetry' => []];

        foreach ($mqtt->events as $entry) {
            $type = (string)$entry['type'];
            if (!self::isCatalogued($type)) {
                continue;
            }
            self::assertTrue(
                CapabilityCatalog::isEventType($type),
                "`{$type}` sai no canal `events` e o catálogo declara-a leitura",
            );
            $seen['events'][] = $type;
        }

        foreach ($mqtt->telemetry as $entry) {
            $type = (string)$entry['type'];
            if (!self::isCatalogued($type)) {
                continue;
            }
            self::assertFalse(
                CapabilityCatalog::isEventType($type),
                "`{$type}` sai no canal `telemetry` e o catálogo declara-a acontecimento",
            );
            $seen['telemetry'][] = $type;
        }

        return $seen;
    }

    private static function isCatalogued(string $type): bool
    {
        static $keys = null;
        if ($keys === null) {
            $keys = [];
            foreach (CapabilityCatalog::definitions() as $definition) {
                $keys[(string)$definition['key']] = true;
            }
        }

        return isset($keys[$type]);
    }

    private static function radarTopic(): string
    {
        return 'radar/1001/' . self::RADAR_UID;
    }

    private static function gatewayTopic(): string
    {
        return 'havicare-hub/null/0/gw/' . self::GATEWAY . '/raw';
    }

    private static function veepooTopic(): string
    {
        return 'havicare-hub/null/0/gw/' . self::VEEPOO_GATEWAY . '/raw';
    }

    /** @param list<int> $bytes */
    private static function radarMessage(string $messageType, array $bytes): string
    {
        return json_encode([
            'payload' => [
                'deviceCode' => self::RADAR_UID,
                $messageType => base64_encode(implode('', array_map('chr', $bytes))),
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Uma pessoa na trama de posições: o byte 13 é a postura e o 5 é a queda confirmada.
     *
     * @return list<int>
     */
    private static function person(int $postureCode): array
    {
        $bytes = array_fill(0, 16, 0);
        $bytes[0] = 1;
        $bytes[1] = 4;
        $bytes[2] = 5;
        $bytes[13] = $postureCode;

        return $bytes;
    }

    /** @return list<int> */
    private static function heartBreath(): array
    {
        $bytes = array_fill(0, 16, 0);
        $bytes[1] = 16;
        $bytes[2] = 72;

        return $bytes;
    }

    private static function buttonScan(int $triggerCount): string
    {
        return json_encode([
            'msg_id' => 3070,
            'device_info' => ['mac' => self::GATEWAY],
            'data' => [[
                'type_code' => 7,
                'type' => 'bxp-button',
                'rssi' => -82,
                'connectable' => 1,
                'mac' => self::BUTTON,
                'frame_type' => 0,
                'passwd_verification' => 1,
                'alarm_status' => 1,
                'trigger_count' => $triggerCount,
                'device_id' => '000001',
                'adv_name' => 'MK Button',
                'batt_vol' => 98,
                'x_axis_data' => -4,
                'y_axis_data' => -20,
                'z_axis_data' => 1052,
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    private static function diaperScan(): string
    {
        return json_encode([
            'msg_id' => 3070,
            'device_info' => ['mac' => self::GATEWAY],
            'data' => [[
                'adv_data' => self::diaperAdvertisement(),
                'rsp_data' => '0f094d4f4e4954204d4543532050524f',
                'type_code' => 10,
                'type' => 'other',
                'rssi' => -83,
                'connectable' => 0,
                'mac' => self::DIAPER_SENSOR,
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    /** Quatro canais acima do limiar: é o que o normalizador lê como fralda a mudar. */
    private static function diaperAdvertisement(): string
    {
        $channels = [...array_fill(0, 10, 1), 13, 13, 13, 13, 1, 1, 1, 1, 1, 1];
        $bits = '000' . str_pad(decbin(80), 7, '0', STR_PAD_LEFT) . '0' . '00' . '000';
        foreach ($channels as $value) {
            $bits .= str_pad(decbin($value), 6, '0', STR_PAD_LEFT);
        }
        foreach ([0x02, 0x02, 0xf9] as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }

        $raw = '';
        for ($offset = 0; $offset < 160; $offset += 8) {
            $raw .= sprintf('%02x', bindec(substr($bits, $offset, 8)));
        }
        $manufacturer = '59000215' . $raw . 'c3';

        return '020104' . sprintf('%02x', strlen($manufacturer) / 2 + 1) . 'ff' . $manufacturer;
    }
}
