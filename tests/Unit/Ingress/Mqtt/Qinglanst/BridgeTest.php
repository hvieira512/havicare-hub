<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Qinglanst;

use Hub\HubMqttBridge;
use Hub\Dashboard\DashboardStoreContract;
use Hub\Ingress\Mqtt\Qinglanst\Bridge;
use Hub\Registry\Whitelist;
use PhpMqtt\Client\MqttClient;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\RecordingHubMqttBridge;
use Tests\Support\Doubles\FakeMqttSubscriber;

final class BridgeTest extends TestCase
{
    /**
     * A notificação de um radar desconhecido leva a licença do tópico.
     *
     * O `ident` é o campo livre que a dashboard desenha como `protocol · ident` quando não
     * há modelo. Levava o UID, que já é a linha de cima da notificação, e por isso o UID
     * aparecia duas vezes e a licença não aparecia de todo. Quem lê a notificação para
     * registar o radar precisa exactamente da licença: é o único campo do formulário que
     * não se deduz do que lá está.
     */
    public function testUnregisteredRadarNotificationCarriesTheTopicLicense(): void
    {
        $whitelistPath = tempnam(sys_get_temp_dir(), 'qinglanst-whitelist-');
        file_put_contents($whitelistPath, '{}');
        $dashboardStore = $this->createMock(DashboardStoreContract::class);
        $dashboardStore->expects(self::once())
            ->method('recordRejectedDevice')
            ->with(
                '9D8A3204F853',
                'qinglanst-radar',
                '',
                'licença 2103',
                'device_not_authorized'
            );
        $bridge = new Bridge(
            new FakeMqttSubscriber(),
            new Whitelist($whitelistPath),
            new RecordingHubMqttBridge(),
            dashboardStore: $dashboardStore,
        );

        $bridge->handleReceivedMessage('radar/2103/9D8A3204F853', '{}');

        @unlink($whitelistPath);
    }

    public function testPublishesUsingUpstreamRadarUidInsteadOfCanonicalWhitelistKey(): void
    {
        $whitelistPath = tempnam(sys_get_temp_dir(), 'qinglanst-whitelist-');
        file_put_contents($whitelistPath, json_encode([
            'radar-canonical-1' => [
                'supplier' => 'Qinglanst',
                'model' => 'RD-V1',
                'deviceType' => 'radar',
                'licenseId' => '1001',
                'company' => 'hitcare',
                'deviceId' => 'radar-topic-uid',
            ],
        ], JSON_THROW_ON_ERROR));

        $mqttBridge = new RecordingHubMqttBridge();
        $bridge = new Bridge(
            new FakeMqttSubscriber(),
            new Whitelist($whitelistPath),
            $mqttBridge,
            decoder: new \Hub\Ingress\Mqtt\Qinglanst\PayloadDecoder(),
            normalizer: new \Hub\Ingress\Mqtt\Qinglanst\MessageNormalizer(),
            commercialModelResolver: new class extends \Hub\CommercialModelResolver {
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

        @unlink($whitelistPath);
    }

    /**
     * @param list<int> $bytes
     */
    private function bytes(array $bytes): string
    {
        return implode('', array_map(static fn (int $byte): string => chr($byte), $bytes));
    }
}


