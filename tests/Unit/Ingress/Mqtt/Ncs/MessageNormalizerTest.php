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
        self::assertSame('Qinglanst RD-V1 Pro', $result['status']['device']['commercialName'] ?? null);
        self::assertSame('Qinglanst RD-V1 Pro', $result['event']['device']['commercialName'] ?? null);
        self::assertSame('Qinglanst RD-V1 Pro', $result['raw']['device']['commercialName'] ?? null);
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
