<?php

namespace Tests\Integration\Dashboard;

use GuzzleHttp\Psr7\ServerRequest;
use Hub\Api\Auth\LoginThrottle;
use Psr\Http\Message\ResponseInterface;
use Tests\Support\DashboardHttpTestCase;
use Tests\Support\Doubles\InMemoryRedisClient;

/**
 * Os tetos do login, que é a única rota pública que faz trabalho a sério.
 *
 * O `password_verify` está a custo 12 -- medido no servidor, 145,6 ms -- é síncrono, e corre
 * no mesmo event loop que serve a ingestão TCP dos relógios. Cerca de sete tentativas por
 * segundo bastavam para parar o processo inteiro, sem autenticação nenhuma.
 */
final class DashboardLoginThrottleTest extends DashboardHttpTestCase
{
    /**
     * A asserção que interessa é a última: a tentativa recusada leva a password **certa**. Se
     * o teto fosse verificado depois do `password_verify`, esta respondia 200 -- e o custo que
     * ele existe para travar já teria sido pago.
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

    /**
     * O teto por endereço é derrotado por quem tenha endereços a rodar, e é o global que fecha
     * essa porta: é ele que fixa o tempo de loop gasto em bcrypt, independentemente de quantos
     * endereços o atacante tenha.
     */
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
     * O caminho do `refresh_token` não chama `password_verify` -- é uma leitura ao Redis e duas
     * escritas. Travá-lo punia justamente o cliente que se porta bem, que é o que renova em vez
     * de voltar a autenticar.
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
     * As janelas vão a uma hora de propósito.
     *
     * A janela vive na chave, como `intdiv(time(), segundos)`, e por isso um teste que a use
     * curta depende do alinhamento do relógio: cada tentativa custa ~150 ms de bcrypt, e três
     * delas atravessam de vez em quando uma fronteira de 10 s -- o contador reinicia a meio, a
     * tentativa que devia ser recusada passa, e o teste falha uma vez em cada vinte. Com uma
     * hora não há fronteira para atravessar.
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
