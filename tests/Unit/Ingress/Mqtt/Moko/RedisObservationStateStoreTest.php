<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Moko;

use Hub\Ingress\Mqtt\Moko\RedisObservationStateStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\InMemoryRedisClient;

/**
 * O estado de observação do MOKO vive em Redis, e as suas chaves têm de expirar.
 *
 * O espaço de chaves é dispositivo × capacidade × gateway (para o `last`) e dispositivo (para
 * o `condition`); sem prazo, uma etiqueta que muda de gateway ou desaparece deixa a sua chave
 * lá para sempre. As chaves só servem para comparar com a leitura anterior dentro da janela de
 * refrescamento, portanto o prazo não altera a decisão de publicar -- só limpa o que já não
 * é consultado.
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
