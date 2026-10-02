<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Qinglanst;

use Hub\State\DeviceStoreContract;
use Hub\Ingress\Mqtt\Qinglanst\QinglanstBridge;
use Hub\Registry\Denylist;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;
use Tests\Support\Doubles\FakeMqttSubscriber;

final class BridgeTest extends TestCase
{
    /**
     * A notificação de um radar desconhecido leva a licença do tópico.
     *
     * O tópico é `radar/{licenseId}/{uid}` e a licença é o único campo do assistente de
     * registo que não se deduz do protocolo -- o tipo e o modelo já vinham. Vai como
     * número e não dentro do `ident`, para a dashboard a poder pré-seleccionar em vez de
     * ter de interpretar uma frase.
     */
    public function testUnregisteredRadarNotificationCarriesTheTopicLicense(): void
    {
        $deviceStore = $this->createMock(DeviceStoreContract::class);
        $deviceStore->expects(self::once())
            ->method('recordRejectedDevice')
            ->with(
                '9D8A3204F853',
                'qinglanst-radar',
                '',
                '9D8A3204F853',
                'device_not_authorized',
                2103
            );
        $bridge = new QinglanstBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist(),
            new RecordingHubMqttBridge(),
            deviceStore: $deviceStore,
        );

        $bridge->handleReceivedMessage('radar/2103/9D8A3204F853', '{}');
    }

    /**
     * Toda a telemetria vai para o histórico, sem amostragem.
     *
     * O histórico é também o que alimenta o stream em directo: travar escritas é travar o
     * mapa, e a lista já está limitada a 100 entradas.
     */
    public function testEveryPositionReadingReachesTheHistory(): void
    {
        $lists = [];
        $deviceStore = $this->createMock(DeviceStoreContract::class);
        $deviceStore->method('append')->willReturnCallback(
            static function (string $imei, string $list) use (&$lists): void {
                $lists[] = $list;
            }
        );

        $bridge = new QinglanstBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                'radar-canonical-1' => IngressFixtures::radar() + ['deviceId' => 'radar-topic-uid'],
            ]),
            new RecordingHubMqttBridge(),
            deviceStore: $deviceStore,
        );

        $message = (string)json_encode([
            'payload' => [
                'deviceCode' => 'radar-topic-uid',
                'position' => base64_encode($this->bytes([
                    0x01, 0x0A, 0x0B, 0x0C, 0, 0, 0, 0, 0, 0, 0, 0, 0x04, 0x01, 0x00, 0x09,
                ])),
            ],
        ]);

        $bridge->handleReceivedMessage('radar/1001/radar-topic-uid', $message);
        $bridge->handleReceivedMessage('radar/1001/radar-topic-uid', $message);

        self::assertCount(
            2,
            array_filter($lists, static fn (string $list): bool => $list === 'telemetry'),
            'as duas leituras de posição têm de ir para o histórico',
        );
    }

    /**
     * A notificação de um radar desconhecido é estrangulada: um radar por registar publica
     * ~20 mensagens por segundo, e sem travão é uma escrita ao MySQL por cada, a reabrir um
     * aviso que o operador nunca consegue marcar como lido.
     */
    public function testUnregisteredRadarNotificationIsThrottled(): void
    {
        $deviceStore = $this->createMock(DeviceStoreContract::class);
        // Duas mensagens seguidas do mesmo radar desconhecido, um só registo.
        $deviceStore->expects(self::once())->method('recordRejectedDevice');
        $bridge = new QinglanstBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist(),
            new RecordingHubMqttBridge(),
            deviceStore: $deviceStore,
        );

        $bridge->handleReceivedMessage('radar/2103/9D8A3204F853', '{}');
        $bridge->handleReceivedMessage('radar/2103/9D8A3204F853', '{}');
    }

    /**
     * Um radar na denylist é ignorado na fonte: nem notificação, nem escrita. É o «não quero
     * mesmo que apareça» -- cala o sino em definitivo, ao contrário do estrangulamento, que só
     * espaça.
     */
    public function testDenylistedRadarProducesNoNotification(): void
    {
        $deviceStore = $this->createMock(DeviceStoreContract::class);
        $deviceStore->expects(self::never())->method('recordRejectedDevice');

        $denylist = new Denylist();
        $denylist->block('9D8A3204F853');

        $bridge = new QinglanstBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist(),
            new RecordingHubMqttBridge(),
            deviceStore: $deviceStore,
            denylist: $denylist,
        );

        $bridge->handleReceivedMessage('radar/2103/9D8A3204F853', '{}');
    }

    public function testPublishesUsingCanonicalWhitelistKeyAndNotTheUpstreamRadarUid(): void
    {
        $mqttBridge = new RecordingHubMqttBridge();
        $bridge = new QinglanstBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                // Chave canónica e UID do tópico diferentes de propósito.
                'radar-canonical-1' => IngressFixtures::radar() + ['deviceId' => 'radar-topic-uid'],
            ]),
            $mqttBridge,
            commercialModelResolver: new class extends \Hub\Device\CommercialModelResolver {
                public function __construct()
                {
                }

                public function resolveCommercialName(string $supplier, string $model): string
                {
                    return $supplier === 'Qinglanst' && $model === 'RD-V1' ? 'Qinglanst RD-V1 Pro' : '';
                }
            },
        );

        $bridge->handleReceivedMessage(
            'radar/1001/radar-topic-uid',
            json_encode([
                'payload' => [
                    'deviceCode' => 'radar-topic-uid',
                    'posstatics' => base64_encode($this->bytes([
                        0x01, 0x02, 0x03, 0x00, 0x2A, 0x05, 0x06, 0x07, 0x08, 0x09, 0x01, 0, 0, 0, 0, 0,
                    ])),
                ],
            ], JSON_THROW_ON_ERROR)
        );

        self::assertSame('radar-canonical-1', $mqttBridge->lastTelemetry()['imei']);
        self::assertSame('radar-canonical-1', $mqttBridge->lastTelemetry()['payload']['device']['id'] ?? null);
        self::assertSame('position_minute_stats', $mqttBridge->lastTelemetry()['payload']['type'] ?? null);
        self::assertSame('Qinglanst RD-V1 Pro', $mqttBridge->lastTelemetry()['payload']['device']['commercialName'] ?? null);
    }

    /**
     * Um radar registado publica o `raw` da mensagem, para debugging -- fala directamente por
     * MQTT, portanto a trama que chega é a mensagem original dele, tal como o relógio e o NCS.
     */
    public function testARegisteredRadarPublishesTheRawMessage(): void
    {
        $mqttBridge = new RecordingHubMqttBridge();
        $bridge = new QinglanstBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                'radar-canonical-1' => IngressFixtures::radar() + ['deviceId' => 'radar-topic-uid'],
            ]),
            $mqttBridge,
        );

        $bridge->handleReceivedMessage(
            'radar/1001/radar-topic-uid',
            json_encode([
                'payload' => [
                    'deviceCode' => 'radar-topic-uid',
                    'posstatics' => base64_encode($this->bytes([
                        0x01, 0x02, 0x03, 0x00, 0x2A, 0x05, 0x06, 0x07, 0x08, 0x09, 0x01, 0, 0, 0, 0, 0,
                    ])),
                ],
            ], JSON_THROW_ON_ERROR)
        );

        self::assertNotEmpty($mqttBridge->raw, 'o radar publica raw');
        $raw = $mqttBridge->raw[0];
        self::assertSame('radar-canonical-1', $raw['imei'], 'o raw vai na chave canónica, não no uid do tópico');
        self::assertSame('uplink', $raw['payload']['direction']);
        self::assertSame('qinglanst-radar', $raw['payload']['debug']['protocol']);
        // O original preservado: o deviceCode que chegou no payload MQTT tem de estar lá.
        self::assertStringContainsString('radar-topic-uid', json_encode($raw['payload']['debug']['payload']));
    }

    /**
     * @param list<int> $bytes
     */
    private function bytes(array $bytes): string
    {
        return implode('', array_map(static fn (int $byte): string => chr($byte), $bytes));
    }
}
