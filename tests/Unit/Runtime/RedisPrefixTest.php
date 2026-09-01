<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Hub\Runtime\HubServices;
use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;

/**
 * O prefixo das chaves do Redis, que é o que permite dois hubs no mesmo servidor.
 *
 * As chaves do hub vivem sob `hub:`, e essa raiz já é partilhada com o reencaminhador -- que
 * lá tem as suas `hub:forward:*` e `hub:crm:target:*`. Uma segunda instância a escrever nas
 * mesmas chaves não daria erro nenhum: escreveria por cima do estado dos dispositivos da
 * primeira, e as sessões das duas dashboards misturavam-se.
 *
 * O prefixo vai no cliente e não em cada store de propósito. São seis espaços de chaves hoje,
 * e o sétimo que aparecesse nascia sem ele -- em silêncio, que é como estas coisas doem.
 */
final class RedisPrefixTest extends TestCase
{
    /** Vazio é produção: as chaves têm de ficar exactamente onde sempre estiveram. */
    public function testAnEmptyPrefixDeclaresNoOptionAtAll(): void
    {
        self::assertSame([], HubServices::redisOptions(['prefix' => '']));
        self::assertSame([], HubServices::redisOptions([]), 'ausente é o mesmo que vazio');
        self::assertSame([], HubServices::redisOptions(['prefix' => '   ']), 'só espaços também');
    }

    public function testAPrefixBecomesAPredisOption(): void
    {
        self::assertSame(['prefix' => 'dev:'], HubServices::redisOptions(['prefix' => 'dev:']));
    }

    /**
     * O prefixo apanha as chaves de todos os stores, e não só as do primeiro.
     *
     * Não fala com nenhum Redis: constrói o comando pelo cliente, que é onde o Predis aplica
     * o prefixo, e olha para o argumento que sairia no fio.
     *
     * @dataProvider hubKeyspaces
     */
    public function testEveryHubKeyspaceGetsThePrefix(string $key): void
    {
        $client = new RedisClient([], HubServices::redisOptions(['prefix' => 'dev:']));

        $command = $client->createCommand('get', [$key]);

        self::assertSame('dev:' . $key, $command->getArgument(0));
    }

    /** As seis raízes que o hub usa hoje, uma por store. */
    public static function hubKeyspaces(): array
    {
        return [
            'dashboard' => ['hub:dashboard:861265061009822:telemetry'],
            'api tokens' => ['hub:api-tokens:abc123'],
            'downlink' => ['hub:downlink:861265061009822'],
            'moko' => ['hub:moko:d48c49f7909c'],
            'location circuit' => ['hub:location:circuit:beacondb'],
            'location resolution' => ['hub:location:resolution:861265061009822'],
        ];
    }

    /**
     * Sem prefixo, a chave sai intacta.
     *
     * É o que garante que ligar isto não mexe em produção: o cliente sem a opção não tem
     * processador nenhum para aplicar.
     */
    public function testWithoutAPrefixTheKeyIsUntouched(): void
    {
        $client = new RedisClient([], HubServices::redisOptions(['prefix' => '']));

        self::assertNull($client->getOptions()->prefix);
    }
}
