<?php

declare(strict_types=1);

namespace Tests\Integration\Dashboard;

use GuzzleHttp\Psr7\ServerRequest;
use Hub\Api\Auth\LoginThrottle;
use Psr\Http\Message\ResponseInterface;
use Tests\Support\DashboardHttpTestCase;
use Tests\Support\Doubles\InMemoryRedisClient;

/**
 * O `password_verify` custa ~146 ms, é síncrono e corre no event loop da ingestão TCP: sem
 * tetos, sete tentativas por segundo param o processo inteiro.
 */
final class DashboardLoginThrottleTest extends DashboardHttpTestCase
{
    /**
     * A tentativa recusada leva a password certa: o teto verifica-se antes do `password_verify`,
     * senão o custo que existe para travar já estaria pago.
     */
    public function testAnAddressThatKeepsGuessingIsRefusedBeforeTheHashIsChecked(): void
    {
        $server = $this->serverWithThrottle(maxPerAddress: 2);

        self::assertSame(401, $this->attempt($server, 'admin', 'errada', '198.51.100.7')->getStatusCode());
        self::assertSame(401, $this->attempt($server, 'admin', 'errada', '198.51.100.7')->getStatusCode());

        $blocked = $this->attempt($server, 'admin', 'secret', '198.51.100.7');
        self::assertSame(429, $blocked->getStatusCode(), (string)$blocked->getBody());
        self::assertStringContainsString('too_many_attempts', (string)$blocked->getBody());
    }

    /** Um endereço não paga pelo outro: senão um vizinho atrás do mesmo NAT tranca a conta. */
    public function testAnotherAddressIsNotPunishedForTheFirstOne(): void
    {
        $server = $this->serverWithThrottle(maxPerAddress: 1);

        self::assertSame(401, $this->attempt($server, 'admin', 'errada', '198.51.100.7')->getStatusCode());
        self::assertSame(429, $this->attempt($server, 'admin', 'errada', '198.51.100.7')->getStatusCode());

        self::assertSame(
            401,
            $this->attempt($server, 'admin', 'errada', '203.0.113.9')->getStatusCode(),
            'o teto é por endereço, e este ainda não gastou o seu'
        );
    }

    /** Contra endereços a rodar é o teto global que fixa o tempo de loop gasto em bcrypt. */
    public function testTheGlobalCapHoldsWhenTheAddressesRotate(): void
    {
        $server = $this->serverWithThrottle(maxPerAddress: 50, maxGlobal: 2);

        self::assertSame(401, $this->attempt($server, 'admin', 'errada', '198.51.100.1')->getStatusCode());
        self::assertSame(401, $this->attempt($server, 'admin', 'errada', '198.51.100.2')->getStatusCode());

        $blocked = $this->attempt($server, 'admin', 'errada', '198.51.100.3');
        self::assertSame(429, $blocked->getStatusCode(), 'endereço novo, mas o processo já gastou o seu tempo');
    }

    /** E o teto por utilizador trava quem distribui as tentativas contra uma conta só. */
    public function testTheUsernameCapHoldsWhenTheAddressesRotate(): void
    {
        $server = $this->serverWithThrottle(maxPerAddress: 50, maxPerUsername: 2, maxGlobal: 50);

        self::assertSame(401, $this->attempt($server, 'admin', 'errada', '198.51.100.1')->getStatusCode());
        self::assertSame(401, $this->attempt($server, 'admin', 'errada', '198.51.100.2')->getStatusCode());

        self::assertSame(
            429,
            $this->attempt($server, 'admin', 'errada', '198.51.100.3')->getStatusCode(),
            'a conta já levou as tentativas que lhe cabiam'
        );
        self::assertSame(
            401,
            $this->attempt($server, 'tenant', 'errada', '198.51.100.4')->getStatusCode(),
            'outra conta não paga pela primeira'
        );
    }

    /**
     * O `refresh_token` não chama `password_verify`; travá-lo puniria o cliente que renova em
     * vez de voltar a autenticar.
     */
    public function testRenewingWithARefreshTokenIsNotThrottled(): void
    {
        $server = $this->serverWithThrottle(maxPerAddress: 1, maxPerUsername: 1, maxGlobal: 1);

        $login = $this->attempt($server, 'admin', 'secret', '198.51.100.7');
        self::assertSame(200, $login->getStatusCode(), (string)$login->getBody());
        $refresh = (string)(json_decode((string)$login->getBody(), true)['token']['refresh_token'] ?? '');
        self::assertNotSame('', $refresh);

        // O login gastou todos os tetos, e a renovação tem de passar mesmo assim.
        for ($round = 0; $round < 3; $round++) {
            $renewed = $server(new ServerRequest(
                'POST',
                '/api/auth/login',
                ['Content-Type' => 'application/json'],
                json_encode(['refresh_token' => $refresh], JSON_THROW_ON_ERROR),
                '1.1',
                ['REMOTE_ADDR' => '198.51.100.7']
            ));
            self::assertSame(200, $renewed->getStatusCode(), (string)$renewed->getBody());

            // A rotação é destrutiva: o token seguinte é o que vale.
            $refresh = (string)(json_decode((string)$renewed->getBody(), true)['token']['refresh_token'] ?? '');
            self::assertNotSame('', $refresh);
        }
    }

    /** Um corpo mal formado nem chega ao teto: não custa bcrypt nenhum. */
    public function testAMalformedBodyIsNotCountedAgainstTheCap(): void
    {
        $server = $this->serverWithThrottle(maxPerAddress: 2);

        for ($round = 0; $round < 5; $round++) {
            $response = $server(new ServerRequest(
                'POST',
                '/api/auth/login',
                ['Content-Type' => 'application/json'],
                json_encode(['username' => 'admin'], JSON_THROW_ON_ERROR),
                '1.1',
                ['REMOTE_ADDR' => '198.51.100.7']
            ));
            self::assertSame(400, $response->getStatusCode());
        }

        self::assertSame(
            401,
            $this->attempt($server, 'admin', 'errada', '198.51.100.7')->getStatusCode(),
            'os pedidos mal formados não gastaram tentativas'
        );
    }

    /**
     * As janelas vão a uma hora de propósito: a janela vive na chave, como `intdiv(time(), s)`,
     * e uma curta faria o teste depender de atravessar uma fronteira do relógio.
     */
    private function serverWithThrottle(
        int $maxPerAddress = 20,
        int $maxPerUsername = 10,
        int $maxGlobal = 15
    ): callable {
        return $this->makeServerWithDatabase(
            loginThrottle: new LoginThrottle(
                new InMemoryRedisClient(),
                maxPerAddress: $maxPerAddress,
                windowPerAddressSeconds: 3600,
                maxPerUsername: $maxPerUsername,
                windowPerUsernameSeconds: 3600,
                maxGlobal: $maxGlobal,
                windowGlobalSeconds: 3600,
            )
        )[0];
    }

    private function attempt(
        callable $server,
        string $username,
        string $password,
        string $address
    ): ResponseInterface {
        return $server(new ServerRequest(
            'POST',
            '/api/auth/login',
            ['Content-Type' => 'application/json'],
            json_encode(['username' => $username, 'password' => $password], JSON_THROW_ON_ERROR),
            '1.1',
            ['REMOTE_ADDR' => $address]
        ));
    }
}
