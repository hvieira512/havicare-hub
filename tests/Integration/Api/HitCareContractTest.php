<?php

declare(strict_types=1);

namespace Tests\Integration\Api;

use GuzzleHttp\Psr7\ServerRequest;
use Tests\Support\DashboardHttpTestCase;

/** O `HubApiRepository` do hitCare pede tokens ao hub e entrega-os às apps dele. */
final class HitCareContractTest extends DashboardHttpTestCase
{
    /** @var callable */
    private $server;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->server] = $this->makeServerWithDatabase();
        $this->token = $this->loginToken($this->server, 'admin', 'secret');
    }

    /** Guarda o hitCare: o `HubApiRepository` lê os quatro campos do token em snake_case, no login e na renovação. */
    public function testLoginAndRefreshGiveTheTokenFieldsHitCareReads(): void
    {
        $login = $this->call('POST', '/api/auth/login', ['username' => 'admin', 'password' => 'secret'], authorized: false);
        $this->assertTokenFields($login);

        $refreshed = $this->call('POST', '/api/auth/login', ['refresh_token' => $login[1]['token']['refresh_token']], authorized: false);
        $this->assertTokenFields($refreshed);

        [$status, $refused] = $this->call('POST', '/api/auth/login', ['username' => 'admin', 'password' => 'wrong'], authorized: false);
        self::assertGreaterThanOrEqual(400, $status);
        self::assertIsString($refused['error']['code'] ?? null);
    }

    /** Guarda o hitCare: o token do inquilino pede-se com o `licenseId` em número e lê-se como o do login. */
    public function testTheLicenseTokenGivesTheTokenFieldsHitCareReads(): void
    {
        $this->assertTokenFields($this->call('POST', '/api/auth/license-token', ['company' => 'hitcare', 'licenseId' => 1001]));
    }

    /** @param array{0: int, 1: array<string, mixed>} $response */
    private function assertTokenFields(array $response): void
    {
        [$status, $body] = $response;
        self::assertSame(200, $status, json_encode($body, JSON_THROW_ON_ERROR));
        self::assertIsString($body['token']['access_token'] ?? null);
        self::assertIsInt($body['token']['expires_in'] ?? null);
        self::assertIsString($body['token']['refresh_token'] ?? null);
        self::assertIsInt($body['token']['refresh_expires_in'] ?? null);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function call(string $method, string $path, array $body, bool $authorized = true): array
    {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if ($authorized) {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }
        $response = ($this->server)(new ServerRequest($method, $path, $headers, json_encode($body, JSON_THROW_ON_ERROR)));
        $decoded = json_decode((string)$response->getBody(), true);

        return [$response->getStatusCode(), is_array($decoded) ? $decoded : []];
    }
}
