<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Qinglanst;

use Hub\Dashboard\DashboardStoreContract;
use Hub\Ingress\Mqtt\Qinglanst\Bridge;
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
        $dashboardStore = $this->createMock(DashboardStoreContract::class);
        $dashboardStore->expects(self::once())
            ->method('recordRejectedDevice')
            ->with(
                '9D8A3204F853',
                'qinglanst-radar',
                '',
                '9D8A3204F853',
                'device_not_authorized',
                2103
            );
        $bridge = new Bridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist(),
            new RecordingHubMqttBridge(),
            dashboardStore: $dashboardStore,
        );

        $bridge->handleReceivedMessage('radar/2103/9D8A3204F853', '{}');
    }

    public function testPublishesUsingUpstreamRadarUidInsteadOfCanonicalWhitelistKey(): void
    {
        $mqttBridge = new RecordingHubMqttBridge();
        $bridge = new Bridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                // A chave canónica e o UID do tópico são diferentes de propósito: é o que
                // este teste prende.
                'radar-canonical-1' => IngressFixtures::radar() + ['deviceId' => 'radar-topic-uid'],
            ]),
            $mqttBridge,
            decoder: new \Hub\Ingress\Mqtt\Qinglanst\PayloadDecoder(),
            normalizer: new \Hub\Ingress\Mqtt\Qinglanst\MessageNormalizer(),
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

        self::assertSame('radar-topic-uid', $mqttBridge->lastTelemetry()['imei']);
        self::assertSame('minute_stats', $mqttBridge->lastTelemetry()['payload']['type'] ?? null);
        self::assertSame('Qinglanst RD-V1 Pro', $mqttBridge->lastTelemetry()['payload']['device']['commercialName'] ?? null);
    }

    /**
     * @param list<int> $bytes
     */
    private function bytes(array $bytes): string
    {
        return implode('', array_map(static fn (int $byte): string => chr($byte), $bytes));
    }
}
