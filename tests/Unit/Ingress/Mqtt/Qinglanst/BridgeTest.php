<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Qinglanst;

use Hub\HubMqttBridge;
use Hub\Ingress\Mqtt\Qinglanst\Bridge;
use Hub\Registry\Whitelist;
use PhpMqtt\Client\MqttClient;
use PHPUnit\Framework\TestCase;

final class BridgeTest extends TestCase
{
    public function testPublishesUsingUpstreamRadarUidInsteadOfCanonicalWhitelistKey(): void
    {
        $whitelistPath = tempnam(sys_get_temp_dir(), 'qinglanst-whitelist-');
        file_put_contents($whitelistPath, json_encode([
            'radar-canonical-1' => [
                'supplier' => 'Qinglanst',
                'model' => 'RD-V1',
                'deviceType' => 'radar',
                'licenseId' => '1001',
                'company' => 'hitCare',
                'deviceId' => 'radar-topic-uid',
            ],
        ], JSON_THROW_ON_ERROR));

        $mqttBridge = new RecordingHubMqttBridge();
        $bridge = new Bridge(
            new FakeSubscriber(),
            new Whitelist($whitelistPath),
            $mqttBridge,
            decoder: new \Hub\Ingress\Mqtt\Qinglanst\PayloadDecoder(),
            normalizer: new \Hub\Ingress\Mqtt\Qinglanst\MessageNormalizer(),
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

        self::assertSame('radar-topic-uid', $mqttBridge->telemetryDeviceKey);
        self::assertSame('minute_stats', $mqttBridge->telemetryPayload['type'] ?? null);

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

final class RecordingHubMqttBridge extends HubMqttBridge
{
    public ?string $telemetryDeviceKey = null;
    public ?array $telemetryPayload = null;

    public function __construct()
    {
    }

    public function publishTelemetry(string $imei, array $payload, string $deviceType = 'watch', string $licenseId = '0', string $company = 'null'): void
    {
        $this->telemetryDeviceKey = $imei;
        $this->telemetryPayload = $payload;
    }
}

final class FakeSubscriber extends MqttClient
{
    public function __construct()
    {
        parent::__construct('127.0.0.1', 1883, 'fake-qinglanst-sub');
    }

    public function subscribe(string $topicFilter, ?callable $callback = null, int $qualityOfService = 0): void
    {
    }
}
