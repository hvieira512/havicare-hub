<?php

namespace Tests\Integration\Dashboard;

use GuzzleHttp\Psr7\ServerRequest;
use Hub\Log\Logger;
use Tests\Support\DashboardHttpTestCase;

/**
 * Quem entra na API, com que credencial, e o que fica escrito no registo.
 *
 * O registo está aqui e não à parte porque é a mesma pergunta vista do outro lado: a
 * credencial que se aceita é a que não pode aparecer no ficheiro.
 */
final class DashboardApiAuthTest extends DashboardHttpTestCase
{
    /**
     * O pedido mal formado é 400 e a credencial recusada é 401.
     *
     * O controlador respondia 401 a qualquer erro do login, e por isso um corpo sem password
     * -- ou que nem sequer era JSON -- chegava ao cliente como "credencial inválida". Quem
     * gera um cliente a partir da especificação não tem como distinguir os dois casos se a
     * API lhes der o mesmo estado.
     */
    public function testApiLoginSeparatesMalformedRequestsFromRejectedCredentials(): void
    {
        $server = $this->makeServer();

        $missingPassword = $server(new ServerRequest(
            'POST',
            '/api/auth/login',
            ['Content-Type' => 'application/json'],
            json_encode(['username' => 'admin'], JSON_THROW_ON_ERROR)
        ));
        self::assertSame(400, $missingPassword->getStatusCode(), (string)$missingPassword->getBody());
        self::assertSame(
            'invalid_request',
            json_decode((string)$missingPassword->getBody(), true, 512, JSON_THROW_ON_ERROR)['error']['code'] ?? null
        );

        $wrongPassword = $server(new ServerRequest(
            'POST',
            '/api/auth/login',
            ['Content-Type' => 'application/json'],
            json_encode(['username' => 'admin', 'password' => 'nao-e-esta'], JSON_THROW_ON_ERROR)
        ));
        self::assertSame(401, $wrongPassword->getStatusCode(), (string)$wrongPassword->getBody());
        self::assertSame(
            'invalid_credentials',
            json_decode((string)$wrongPassword->getBody(), true, 512, JSON_THROW_ON_ERROR)['error']['code'] ?? null
        );

        $badRefresh = $server(new ServerRequest(
            'POST',
            '/api/auth/login',
            ['Content-Type' => 'application/json'],
            json_encode(['refresh_token' => 'nao-existe'], JSON_THROW_ON_ERROR)
        ));
        self::assertSame(401, $badRefresh->getStatusCode(), (string)$badRefresh->getBody());
    }

    public function testApiLoginIssuesBearerTokenAndAllowsApiAccess(): void
    {
        $server = $this->makeServer();

        $login = $server(new ServerRequest(
            'POST',
            '/api/auth/login',
            ['Content-Type' => 'application/json'],
            json_encode(['username' => 'admin', 'password' => 'secret'], JSON_THROW_ON_ERROR)
        ));

        self::assertSame(200, $login->getStatusCode(), (string)$login->getBody());
        $payload = json_decode((string)$login->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $token = (string)($payload['token']['access_token'] ?? '');
        $refreshToken = (string)($payload['token']['refresh_token'] ?? '');
        self::assertSame('admin', $payload['token']['username'] ?? null);
        self::assertNotSame('', $token);
        self::assertNotSame('', $refreshToken);

        $response = $server(new ServerRequest(
            'GET',
            '/api/devices',
            ['Authorization' => 'Bearer ' . $token]
        ));

        self::assertSame(200, $response->getStatusCode());

        $refreshAsBearer = $server(new ServerRequest(
            'GET',
            '/api/devices',
            ['Authorization' => 'Bearer ' . $refreshToken]
        ));
        self::assertSame(401, $refreshAsBearer->getStatusCode(), (string)$refreshAsBearer->getBody());

        $filters = $server(new ServerRequest(
            'GET',
            '/api/device-types/suppliers',
            ['Authorization' => 'Bearer ' . $token]
        ));

        self::assertSame(200, $filters->getStatusCode(), (string)$filters->getBody());
    }

    public function testApiRefreshTokenReissuesAccessAfterAccessTokenExpires(): void
    {
        $server = $this->makeServer(apiTokenTtlSeconds: 1, apiRefreshTokenTtlSeconds: 60);
        $login = $server(new ServerRequest(
            'POST',
            '/api/auth/login',
            ['Content-Type' => 'application/json'],
            json_encode(['username' => 'admin', 'password' => 'secret'], JSON_THROW_ON_ERROR)
        ));

        self::assertSame(200, $login->getStatusCode(), (string)$login->getBody());
        $loginPayload = json_decode((string)$login->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $expiredAccessToken = (string)($loginPayload['token']['access_token'] ?? '');
        $refreshToken = (string)($loginPayload['token']['refresh_token'] ?? '');

        self::assertNotSame('', $expiredAccessToken);
        self::assertNotSame('', $refreshToken);
        sleep(2);

        $expiredResponse = $server(new ServerRequest(
            'GET',
            '/api/devices',
            ['Authorization' => 'Bearer ' . $expiredAccessToken]
        ));

        self::assertSame(401, $expiredResponse->getStatusCode(), (string)$expiredResponse->getBody());

        $refresh = $server(new ServerRequest(
            'POST',
            '/api/auth/login',
            ['Content-Type' => 'application/json'],
            json_encode(['refresh_token' => $refreshToken], JSON_THROW_ON_ERROR)
        ));

        self::assertSame(200, $refresh->getStatusCode(), (string)$refresh->getBody());
        $payload = json_decode((string)$refresh->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $newToken = (string)($payload['token']['access_token'] ?? '');
        $newRefreshToken = (string)($payload['token']['refresh_token'] ?? '');

        self::assertNotSame('', $newToken);
        self::assertNotSame('', $newRefreshToken);
        self::assertNotSame($expiredAccessToken, $newToken);
        self::assertNotSame($refreshToken, $newRefreshToken);

        $log = $this->apiLogContents();
        self::assertStringNotContainsString($expiredAccessToken, $log);
        self::assertStringNotContainsString($refreshToken, $log);
        self::assertStringNotContainsString($newToken, $log);
        self::assertStringNotContainsString($newRefreshToken, $log);
        self::assertStringContainsString('"refresh_token":"********"', $log);

        $response = $server(new ServerRequest(
            'GET',
            '/api/devices',
            ['Authorization' => 'Bearer ' . $newToken]
        ));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
    }

    public function testApiLoginLogsStructuredBodyAndReturnsRequestIdHeader(): void
    {
        $server = $this->makeServer();
        $body = json_encode(['username' => 'admin', 'password' => 'secret'], JSON_THROW_ON_ERROR);
        $requestId = 'req-login-123';

        $response = $server(new ServerRequest(
            'POST',
            '/api/auth/login',
            ['Content-Type' => 'application/json', 'X-Request-Id' => $requestId],
            $body
        ));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame($requestId, $response->getHeaderLine('X-Request-Id'));

        $log = $this->apiLogContents();
        self::assertStringContainsString('API login accepted', $log);
        self::assertStringContainsString('API request completed', $log);
        self::assertStringContainsString('"request_id":"' . $requestId . '"', $log);
        self::assertStringContainsString(
            '"request_body":{"username":"admin","password":"********"}',
            $log
        );
        self::assertStringNotContainsString('"password":"secret"', $log);
        self::assertStringContainsString('"response_content_type":"application/json"', $log);
        self::assertStringContainsString('"response_body":{"status":"ok","token":', $log);

        $responsePayload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString((string)$responsePayload['token']['access_token'], $log);
        self::assertStringNotContainsString((string)$responsePayload['token']['refresh_token'], $log);
        self::assertStringContainsString('"access_token":"********"', $log);
        self::assertStringContainsString('"refresh_token":"********"', $log);
    }

    public function testApiLoggingRedactsBeforeCappingAnOversizedBody(): void
    {
        $server = $this->makeServer();
        $body = json_encode([
            'username' => 'admin',
            'password' => 'secret',
            'filler' => str_repeat('a', 40000),
        ], JSON_THROW_ON_ERROR);

        $server(new ServerRequest(
            'POST',
            '/api/auth/login',
            ['Content-Type' => 'application/json'],
            $body
        ));

        $log = $this->apiLogContents();
        // O corpo cortado viaja como texto dentro do registo, e por isso com as aspas escapadas.
        self::assertStringContainsString('bytes no total]', $log);
        self::assertStringContainsString('password\":\"********', $log);
        self::assertStringNotContainsString('secret', $log);
    }

    public function testApiLoggingKeepsFullBodiesWithoutEllipsis(): void
    {
        $payload = str_repeat('abc123', 800);
        Logger::channel('api')->info('large body', ['response_body' => ['payload' => $payload]]);

        $log = $this->apiLogContents();
        self::assertStringContainsString('"payload":"' . $payload . '"', $log);
        self::assertStringNotContainsString('...', $log);
        self::assertGreaterThan(4096, strlen($log));
    }

    public function testLoggerStopsNormalizingUnboundedlyDeepContext(): void
    {
        $value = ['leaf' => 'complete'];
        for ($depth = 0; $depth < 40; $depth++) {
            $value = ['nested' => $value];
        }

        Logger::channel('api')->info('deep context', ['body' => $value]);

        $log = $this->apiLogContents();
        self::assertStringContainsString('levels deep, aborting normalization', $log);
        self::assertStringNotContainsString('"leaf":"complete"', $log);
    }

    public function testLoggerKeepsTheDepthOfARealDeviceResponse(): void
    {
        $value = ['leaf' => 'complete'];
        for ($depth = 0; $depth < 12; $depth++) {
            $value = ['nested' => $value];
        }

        Logger::channel('api')->info('device response', ['body' => $value]);

        $log = $this->apiLogContents();
        self::assertStringContainsString('"leaf":"complete"', $log);
        self::assertStringNotContainsString('aborting normalization', $log);
    }

    public function testUnauthorizedApiRequestIsStillLogged(): void
    {
        $server = $this->makeServer();

        $response = $server(new ServerRequest(
            'GET',
            '/api/devices'
        ));

        self::assertSame(401, $response->getStatusCode(), (string)$response->getBody());
        self::assertNotSame('', $response->getHeaderLine('X-Request-Id'));

        $log = $this->apiLogContents();
        self::assertStringContainsString('API request completed', $log);
        self::assertStringContainsString('"status":401', $log);
        self::assertStringContainsString('"auth_state":"missing"', $log);
        self::assertStringContainsString('"response_content_type":"application/json"', $log);
        self::assertStringContainsString('"response_body":{"error":{"code":"unauthorized"', $log);
    }

    public function testApiDocsAndOpenApiJsonArePublicWhileDevicesRemainProtected(): void
    {
        $server = $this->makeServer();

        $docs = $server(new ServerRequest('GET', '/api/docs'));
        self::assertSame(200, $docs->getStatusCode(), (string)$docs->getBody());
        self::assertSame('text/html; charset=utf-8', $docs->getHeaderLine('Content-Type'));

        $openapi = $server(new ServerRequest('GET', '/api/openapi.json'));
        self::assertSame(200, $openapi->getStatusCode(), (string)$openapi->getBody());
        self::assertSame('application/json', $openapi->getHeaderLine('Content-Type'));

        $protected = $server(new ServerRequest('GET', '/api/devices'));
        self::assertSame(401, $protected->getStatusCode(), (string)$protected->getBody());
    }

    public function testOpenApiSpecAdvertisesBearerAuth(): void
    {
        $spec = \Hub\Api\OpenApiSpec::get();

        self::assertSame(['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Use the bearer token returned by /api/auth/login.']], $spec['components']['securitySchemes'] ?? null);
        self::assertSame([['bearerAuth' => []]], $spec['security'] ?? null);
        self::assertSame([], $spec['paths']['/api/auth/login']['post']['security'] ?? null);
        self::assertArrayNotHasKey('/api/auth/refresh', $spec['paths']);
        self::assertSame([], $spec['paths']['/api/openapi.json']['get']['security'] ?? null);
        self::assertSame([], $spec['paths']['/api/docs']['get']['security'] ?? null);
        self::assertArrayHasKey('/api/devices/{imei}/configurations', $spec['paths']);
    }

    public function testApiCanBeExposedWithoutAuthForDevelopment(): void
    {
        $server = $this->makeServer(apiAuthRequired: false);

        $response = $server(new ServerRequest('GET', '/api/devices'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
    }

    public function testApiRejectsBasicAuthWhileDashboardIsPublic(): void
    {
        $server = $this->makeServer();
        $basic = 'Basic ' . base64_encode('admin:secret');

        $apiResponse = $server(new ServerRequest('GET', '/api/devices', ['Authorization' => $basic]));
        self::assertSame(401, $apiResponse->getStatusCode());

        $dashboardResponse = $server(new ServerRequest('GET', '/dashboard'));
        self::assertSame(200, $dashboardResponse->getStatusCode());
    }
}
