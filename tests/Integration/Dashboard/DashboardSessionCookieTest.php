<?php

namespace Tests\Integration\Dashboard;

use GuzzleHttp\Psr7\ServerRequest;
use Hub\Api\Http\SessionCookie;
use Tests\Support\DashboardHttpTestCase;

/**
 * A sessão da dashboard vive num cookie `HttpOnly`, e é isso que a faz atravessar separadores.
 *
 * O token de renovação estava no `sessionStorage`, que é por aba: abrir a dashboard num
 * separador novo mostrava o login outra vez, com a sessão do primeiro ainda aberta.
 */
final class DashboardSessionCookieTest extends DashboardHttpTestCase
{
    public function testLoginPutsTheRefreshTokenInAnHttpOnlyCookieAndNotInTheBody(): void
    {
        $server = $this->makeServer();

        $login = $this->login($server);

        self::assertSame(200, $login->getStatusCode(), (string)$login->getBody());
        $payload = json_decode((string)$login->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotSame('', (string)($payload['token']['access_token'] ?? ''));
        // O que o JavaScript não pode ler também não lhe é entregue pelo corpo.
        self::assertArrayNotHasKey('refresh_token', $payload['token']);

        $cookie = $login->getHeaderLine('Set-Cookie');
        self::assertStringContainsString(SessionCookie::NAME . '=', $cookie);
        self::assertStringContainsString('HttpOnly', $cookie);
        self::assertStringContainsString('SameSite=Strict', $cookie);
        self::assertStringContainsString('Path=/api/auth', $cookie);
        self::assertNotSame('', $this->sessionValue($login));
    }

    /** O separador novo: sem nada em memória, só com o cookie que o browser reenvia. */
    public function testASecondTabRestoresTheSessionFromTheCookieAlone(): void
    {
        $server = $this->makeServer();
        $login = $this->login($server);
        $firstToken = json_decode((string)$login->getBody(), true, 512, JSON_THROW_ON_ERROR)['token']['access_token'];

        $secondTab = $server($this->withSession(
            new ServerRequest('POST', '/api/auth/login', ['Content-Type' => 'application/json'], '{}'),
            $this->sessionValue($login)
        ));

        self::assertSame(200, $secondTab->getStatusCode(), (string)$secondTab->getBody());
        $token = json_decode((string)$secondTab->getBody(), true, 512, JSON_THROW_ON_ERROR)['token'];
        self::assertSame('hub_admin', $token['role'] ?? null);
        self::assertNotSame('', (string)($token['access_token'] ?? ''));
        self::assertNotSame($firstToken, $token['access_token']);
        // A renovação roda o cookie, e o separador novo leva o valor rodado.
        self::assertNotSame('', $this->sessionValue($secondTab));

        $whoami = $server(new ServerRequest(
            'GET',
            '/api/devices',
            ['Authorization' => 'Bearer ' . $token['access_token']]
        ));
        self::assertSame(200, $whoami->getStatusCode(), (string)$whoami->getBody());
    }

    /**
     * Sem cookie e sem credenciais não há sessão para restaurar, e a resposta é a mesma de
     * sempre: 400, porque o pedido não traz com que autenticar. O cookie inválido é que é 401.
     */
    public function testRestoringWithoutCookieIsRejected(): void
    {
        $server = $this->makeServer();

        $noCookie = $server(new ServerRequest(
            'POST',
            '/api/auth/login',
            ['Content-Type' => 'application/json'],
            '{}'
        ));
        self::assertSame(400, $noCookie->getStatusCode(), (string)$noCookie->getBody());

        $staleCookie = $server($this->withSession(
            new ServerRequest('POST', '/api/auth/login', ['Content-Type' => 'application/json'], '{}'),
            'ja-nao-existe'
        ));
        self::assertSame(401, $staleCookie->getStatusCode(), (string)$staleCookie->getBody());
    }

    /**
     * Terminar sessão apaga o cookie e queima as duas credenciais.
     *
     * Um logout que deixasse o token de acesso vivo dava uma hora de API a quem ficasse com
     * ele, e o cookie por apagar reabria a sessão no separador seguinte.
     */
    public function testLogoutClearsTheCookieAndRevokesBothTokens(): void
    {
        $server = $this->makeServer();
        $login = $this->login($server);
        $accessToken = json_decode((string)$login->getBody(), true, 512, JSON_THROW_ON_ERROR)['token']['access_token'];
        $session = $this->sessionValue($login);

        $logout = $server($this->withSession(
            new ServerRequest('POST', '/api/auth/logout', ['Authorization' => 'Bearer ' . $accessToken]),
            $session
        ));
        self::assertSame(200, $logout->getStatusCode(), (string)$logout->getBody());
        self::assertStringContainsString('Max-Age=0', $logout->getHeaderLine('Set-Cookie'));

        $withOldAccess = $server(new ServerRequest(
            'GET',
            '/api/devices',
            ['Authorization' => 'Bearer ' . $accessToken]
        ));
        self::assertSame(401, $withOldAccess->getStatusCode(), (string)$withOldAccess->getBody());

        $withOldCookie = $server($this->withSession(
            new ServerRequest('POST', '/api/auth/login', ['Content-Type' => 'application/json'], '{}'),
            $session
        ));
        self::assertSame(401, $withOldCookie->getStatusCode(), (string)$withOldCookie->getBody());
    }

    private function login(callable $server): \Psr\Http\Message\ResponseInterface
    {
        return $server(new ServerRequest(
            'POST',
            '/api/auth/login',
            ['Content-Type' => 'application/json'],
            json_encode(
                ['username' => 'admin', 'password' => 'secret', 'session' => 'cookie'],
                JSON_THROW_ON_ERROR
            )
        ));
    }

    private function sessionValue(\Psr\Http\Message\ResponseInterface $response): string
    {
        foreach ($response->getHeader('Set-Cookie') as $cookie) {
            if (preg_match('/^' . preg_quote(SessionCookie::NAME, '/') . '=([^;]*)/', $cookie, $match) === 1) {
                return $match[1];
            }
        }

        return '';
    }

    private function withSession(ServerRequest $request, string $value): ServerRequest
    {
        return $request->withHeader('Cookie', SessionCookie::NAME . '=' . $value);
    }
}
