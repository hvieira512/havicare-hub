<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\HubMqttBridge;
use PhpMqtt\Client\MqttClient;
use PHPUnit\Framework\TestCase;

/**
 * O estado de um dispositivo é publicado como retido, e por isso um dispositivo que muda de
 * cliente continua a anunciar-se no tópico que deixou. Quem subscreve o cliente antigo
 * continua a receber um dispositivo que já não é dele -- o que é uma fuga entre clientes, e
 * não só dados velhos.
 *
 * O MQTT apaga uma mensagem retida com um payload de comprimento zero. Um documento JSON
 * vazio substituía-a por "[]" e a fuga mantinha-se.
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
