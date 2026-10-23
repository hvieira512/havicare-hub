<?php

declare(strict_types=1);

namespace Tests\Unit\Api\Http;

use GuzzleHttp\Psr7\ServerRequest;
use Hub\Api\Http\RequestContext;
use PHPUnit\Framework\TestCase;

/** Atrás do proxy, os endereços absolutos que a API devolve levam o esquema que o browser usou. */
final class RequestContextBaseUrlTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('TRUSTED_PROXIES');
    }

    public function testTheSchemeTheTrustedProxyDeclaredWins(): void
    {
        putenv('TRUSTED_PROXIES=10.70.0.10');

        self::assertSame('https://hub.havicare.com', RequestContext::baseUrl($this->request('10.70.0.10')));
    }

    /** Sem a lista, só o proxy na própria máquina é de confiança. */
    public function testAProxyOutsideTheListCannotChangeTheScheme(): void
    {
        self::assertSame('http://hub.havicare.com', RequestContext::baseUrl($this->request('10.70.0.10')));
    }

    public function testTheListAlsoDecidesWhoseForwardedAddressCounts(): void
    {
        putenv('TRUSTED_PROXIES=127.0.0.1, 10.70.0.10');
        $request = $this->request('10.70.0.10')->withHeader('X-Forwarded-For', '203.0.113.9');

        self::assertSame('203.0.113.9', RequestContext::clientAddress($request));
    }

    private function request(string $remote): ServerRequest
    {
        return (new ServerRequest('GET', 'http://hub.havicare.com/api/models', [], null, '1.1', ['REMOTE_ADDR' => $remote]))
            ->withHeader('X-Forwarded-Proto', 'https');
    }
}
