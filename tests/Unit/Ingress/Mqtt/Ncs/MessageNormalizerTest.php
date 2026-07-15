<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Ncs;

use Hub\Ingress\Mqtt\Ncs\MessageNormalizer;
use Hub\Ingress\Mqtt\Ncs\Topic;
use PHPUnit\Framework\TestCase;

final class MessageNormalizerTest extends TestCase
{
    public function testNormalizesStatusAndIncludesCommercialName(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = Topic::parse('/voerka/1001/devices/1001/status/online');

        $result = $normalizer->normalize($topic, [
            'from' => '1001',
            'type' => 'status',
            'payload' => [
                'status' => ['online' => true],
            ],
        ], $this->device());

        self::assertSame('online', $result['status']['state'] ?? null);
        self::assertArrayNotHasKey('data', $result['status'] ?? []);
        self::assertArrayNotHasKey('source', $result['status'] ?? []);
        self::assertSame('device.connected', $result['event']['type'] ?? null);
        self::assertArrayNotHasKey('source', $result['event'] ?? []);
        self::assertSame('Qinglanst RD-V1 Pro', $result['status']['device']['commercialName'] ?? null);
        self::assertSame('Qinglanst RD-V1 Pro', $result['event']['device']['commercialName'] ?? null);
        self::assertSame('Qinglanst RD-V1 Pro', $result['raw']['device']['commercialName'] ?? null);
    }

    public function testNormalizesHelpCallPagerEventWithoutTelemetryOrSource(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = Topic::parse('/voerka/1001/devices/gw-001/events');

        $result = $normalizer->normalize($topic, [
            'from' => 'gw-001',
            'type' => 6,
            'timestamp' => 372315009,
            'payload' => [
                'id' => '482929',
                'key' => '8',
                'code' => 4000,
                'result' => 1,
                'location' => [
                    'map' => 'google',
                    'x' => 1.12,
                    'y' => 2.23,
                    'z' => 3.34,
                ],
            ],
        ], $this->device());

        self::assertSame('help_call', $result['event']['type'] ?? null);
        self::assertSame('482929', $result['event']['data']['pagerId'] ?? null);
        self::assertArrayNotHasKey('source', $result['event'] ?? []);
        self::assertArrayNotHasKey('telemetry', $result);
        self::assertSame('/voerka/1001/devices/gw-001/events', $result['raw']['debug']['sourceTopic'] ?? null);
    }

    public function testNormalizesResetPagerEventForKnownResetKey(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = Topic::parse('/voerka/1001/devices/gw-001/events');

        $result = $normalizer->normalize($topic, [
            'from' => 'gw-001',
            'type' => 6,
            'timestamp' => 372317236,
            'payload' => [
                'id' => '482929',
                'key' => '1',
                'code' => 4000,
            ],
        ], $this->device());

        self::assertSame('reset', $result['event']['type'] ?? null);
        self::assertSame('482929', $result['event']['data']['pagerId'] ?? null);
        self::assertArrayNotHasKey('source', $result['event'] ?? []);
        self::assertArrayNotHasKey('telemetry', $result);
    }

    public function testDiscardsUnmappedPagerEventKeysFromNormalization(): void
    {
        $normalizer = new MessageNormalizer();
        $topic = Topic::parse('/voerka/1001/devices/gw-001/events');

        $result = $normalizer->normalize($topic, [
            'from' => 'gw-001',
            'type' => 6,
            'timestamp' => 372317236,
            'payload' => [
                'id' => '482929',
                'key' => '99',
                'code' => 4000,
            ],
        ], $this->device());

        self::assertArrayHasKey('raw', $result);
        self::assertArrayNotHasKey('event', $result);
        self::assertArrayNotHasKey('telemetry', $result);
    }

    /**
     * @return array{imei: string, supplier: string, model: string, commercialName: string, deviceType: string, licenseId: string, simNumber: string, deviceId: string}
     */
    private function device(): array
    {
        return [
            'imei' => 'radar-canonical-1',
            'supplier' => 'Qinglanst',
            'model' => 'RD-V1',
            'commercialName' => 'Qinglanst RD-V1 Pro',
            'deviceType' => 'radar',
            'licenseId' => '1001',
            'simNumber' => '',
            'deviceId' => 'radar-topic-uid',
        ];
    }
}
