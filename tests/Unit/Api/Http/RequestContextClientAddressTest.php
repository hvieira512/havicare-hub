<?php

declare(strict_types=1);

namespace Tests\Unit\Api\Http;

use GuzzleHttp\Psr7\ServerRequest;
use Hub\Api\Http\RequestContext;
use PHPUnit\Framework\TestCase;

/**
 * O endereço do cliente atrás do proxy.
 *
 * Com o nginx à frente, o `REMOTE_ADDR` é sempre `127.0.0.1`, e o `LoginThrottle` conta
 * por endereço: sem isto, todos os utilizadores partilham o mesmo balde de vinte
 * tentativas por minuto e a vigésima primeira tranca toda a gente.
 */
final class RequestContextClientAddressTest extends TestCase
{
    /** Sem proxy pelo meio, o endereço é o da ligação. */
    public function testUsesRemoteAddrWhenThereIsNoProxy(): void
    {
        $request = new ServerRequest('GET', '/api/v1/devices', [], null, '1.1', [
            'REMOTE_ADDR' => '203.0.113.9',
        ]);

        self::assertSame('203.0.113.9', RequestContext::clientAddress($request));
    }

    /** Vindo do proxy local, vale o que ele declarou. */
    public function testUsesForwardedHeaderWhenRequestComesFromLocalProxy(): void
    {
        $request = new ServerRequest('GET', '/api/v1/devices', [
            'X-Forwarded-For' => '203.0.113.9',
        ], null, '1.1', ['REMOTE_ADDR' => '127.0.0.1']);

        self::assertSame('203.0.113.9', RequestContext::clientAddress($request));
    }

    /**
     * O caso que decide a segurança disto. O nginx usa `$proxy_add_x_forwarded_for`, que
     * **acrescenta** o endereço da ligação ao que o cliente tiver mandado. Um cliente que
     * forje o cabeçalho produz `1.2.3.4, <endereço real>` -- portanto o valor de confiança
     * é o **último**, e não o primeiro. Ler o primeiro deixava qualquer pessoa escolher o
     * seu endereço e escapar ao estrangulamento de tentativas.
     */
    public function testTakesTheLastEntrySoAClientCannotForgeItsAddress(): void
    {
        $request = new ServerRequest('GET', '/api/auth/login', [
            'X-Forwarded-For' => '1.2.3.4, 203.0.113.9',
        ], null, '1.1', ['REMOTE_ADDR' => '127.0.0.1']);

        self::assertSame('203.0.113.9', RequestContext::clientAddress($request));
    }

    /** De um endereço que não é o proxy, o cabeçalho não vale nada. */
    public function testIgnoresForwardedHeaderFromAnUntrustedAddress(): void
    {
        $request = new ServerRequest('GET', '/api/v1/devices', [
            'X-Forwarded-For' => '203.0.113.9',
        ], null, '1.1', ['REMOTE_ADDR' => '198.51.100.7']);

        self::assertSame('198.51.100.7', RequestContext::clientAddress($request));
    }

    /** O proxy também pode chegar por IPv6 de loopback. */
    public function testTrustsIpv6Loopback(): void
    {
        $request = new ServerRequest('GET', '/api/v1/devices', [
            'X-Forwarded-For' => '203.0.113.9',
        ], null, '1.1', ['REMOTE_ADDR' => '::1']);

        self::assertSame('203.0.113.9', RequestContext::clientAddress($request));
    }

    /** Um cabeçalho vazio ou só com lixo não apaga o endereço da ligação. */
    public function testFallsBackToRemoteAddrWhenForwardedHeaderIsUnusable(): void
    {
        $request = new ServerRequest('GET', '/api/v1/devices', [
            'X-Forwarded-For' => '   ,  ',
        ], null, '1.1', ['REMOTE_ADDR' => '127.0.0.1']);

        self::assertSame('127.0.0.1', RequestContext::clientAddress($request));
    }
}
