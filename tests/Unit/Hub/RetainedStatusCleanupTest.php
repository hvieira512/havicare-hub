<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\HubMqttBridge;
use PhpMqtt\Client\MqttClient;
use PHPUnit\Framework\TestCase;

/**
 * Device status is published retained, so a device that changes tenant keeps
 * announcing itself on the topic it left. A subscriber to the old tenant goes
 * on receiving a device that is no longer theirs -- which is a cross-tenant
 * leak, not just stale data.
 *
 * MQTT deletes a retained message with a zero-length payload. An empty JSON
 * document would replace it with "[]" and the leak would remain.
 */
final class RetainedStatusCleanupTest extends TestCase
{
    public function testClearingPublishesAZeroLengthRetainedPayloadToTheOldTenantTopic(): void
    {
        $publisher = $this->createMock(MqttClient::class);
        $captured = [];
        $publisher->method('publish')->willReturnCallback(
            static function (string $topic, string $message, int $qos = 0, bool $retain = false) use (&$captured): void {
                $captured[] = compact('topic', 'message', 'qos', 'retain');
            }
        );

        (new HubMqttBridge($publisher, 'havicare-hub'))
            ->clearRetainedStatus('hitcare', 1001, 'watch', '861265061009822');

        self::assertCount(1, $captured);
        self::assertSame(
            'havicare-hub/hitcare/1001/watch/861265061009822/status',
            $captured[0]['topic']
        );
        self::assertSame('', $captured[0]['message'], 'a retained message is only deleted by an empty payload');
        self::assertTrue($captured[0]['retain'], 'the delete itself must be retained');
    }

    public function testTheUnassignedTopicCanBeClearedToo(): void
    {
        $publisher = $this->createMock(MqttClient::class);
        $topic = null;
        $publisher->method('publish')->willReturnCallback(
            static function (string $t, string $m, int $q = 0, bool $r = false) use (&$topic): void {
                $topic = $t;
            }
        );

        (new HubMqttBridge($publisher, 'havicare-hub'))
            ->clearRetainedStatus('null', 0, 'gateway', 'd48c49f7909c');

        self::assertSame('havicare-hub/null/0/gateway/d48c49f7909c/status', $topic);
    }
}
