<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Gateway;

use Hub\Ingress\Mqtt\Gateway\RedisObservationStateStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\InMemoryRedisClient;

/**
 * Uma etiqueta que muda de gateway ou desaparece deixaria a chave para sempre; as chaves só servem
 * dentro da janela de refrescamento, e o prazo não altera a decisão de publicar.
 */
final class RedisObservationStateStoreTest extends TestCase
{
    public function testShouldPublishKeyHasATtl(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new RedisObservationStateStore($redis);

        $store->shouldPublish('AA', 'battery', ['data' => ['percent' => 80]], 60, 'GW1');

        $ttl = $redis->ttlFor('hub:moko:last:AA:battery:GW1');
        self::assertNotNull($ttl, 'a chave last tem de expirar');
        self::assertGreaterThanOrEqual(60, $ttl, 'o prazo não pode ser mais curto que a janela de refrescamento');
    }

    public function testConditionKeyHasATtl(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new RedisObservationStateStore($redis);

        $store->transitionCondition('AA', 'dry');

        self::assertNotNull($redis->ttlFor('hub:moko:condition:AA'), 'a chave condition tem de expirar');
    }

    /** O prazo não muda a lógica: dentro da janela, a mesma leitura não volta a publicar. */
    public function testDeduplicationStillHoldsWithinTheWindow(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new RedisObservationStateStore($redis);
        $payload = ['data' => ['percent' => 80]];

        self::assertTrue($store->shouldPublish('AA', 'battery', $payload, 60, 'GW1'), 'a primeira leitura publica');
        self::assertFalse($store->shouldPublish('AA', 'battery', $payload, 60, 'GW1'), 'a repetida, dentro da janela, não');
    }

    /** E uma leitura diferente publica na mesma, prazo ou não. */
    public function testAChangedReadingStillPublishes(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new RedisObservationStateStore($redis);

        self::assertTrue($store->shouldPublish('AA', 'battery', ['data' => ['percent' => 80]], 60, 'GW1'));
        self::assertTrue($store->shouldPublish('AA', 'battery', ['data' => ['percent' => 50]], 60, 'GW1'));
    }

    /** A condição repetida não é transição; uma nova é. */
    public function testTransitionOnlyFiresOnChange(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new RedisObservationStateStore($redis);

        self::assertSame(['previous' => null], $store->transitionCondition('AA', 'dry'));
        self::assertNull($store->transitionCondition('AA', 'dry'), 'a mesma condição não é transição');
        self::assertSame(['previous' => 'dry'], $store->transitionCondition('AA', 'wet'));
    }
}
