<?php

declare(strict_types=1);

namespace Tests\Component\Redis;

use App\Redis\Client;
use PHPUnit\Framework\TestCase;

final class ClientMappingTest extends TestCase
{
    public function testMapCommandEntriesMapsPayloadAndMetadata(): void
    {
        $reflection = new \ReflectionClass(Client::class);
        /** @var Client $client */
        $client = $reflection->newInstanceWithoutConstructor();

        $method = $reflection->getMethod('mapCommandEntries');

        $mapped = $method->invoke($client, [
            'cmd:stream' => [
                '1716300000000-0' => [
                    'imei' => '865028000000306',
                    'type' => 'dnHeartRate',
                    'payload' => '{"a":1}',
                    'request_id' => 'req-1',
                    'feature' => 'heart_rate',
                    'source' => 'api',
                ],
            ],
        ], true);

        self::assertCount(1, $mapped);
        self::assertSame('1716300000000-0', $mapped[0]['streamId']);
        self::assertSame('865028000000306', $mapped[0]['imei']);
        self::assertSame(['a' => 1], $mapped[0]['data']);
        self::assertTrue($mapped[0]['isPending']);
    }
}
