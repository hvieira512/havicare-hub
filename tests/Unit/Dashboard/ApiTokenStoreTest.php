<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use Hub\Api\Auth\ApiAuthContext;
use Hub\Api\Auth\ApiTokenStore;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;
use Predis\Command\CommandInterface;

final class ApiTokenStoreTest extends TestCase
{
    public function testRefreshTokenReissuesAccessAfterAccessTokenExpires(): void
    {
        $store = new ApiTokenStore(new ExpiringRedisClientForApiTokenStoreTest(), 'test:api-tokens');
        $pair = $store->issueTokenPair('admin', ApiAuthContext::ROLE_HUB_ADMIN, 1, 60);

        self::assertSame('admin', $pair['username']);
        self::assertInstanceOf(ApiAuthContext::class, $store->context($pair['access_token']));
        self::assertNull($store->context($pair['refresh_token']));

        sleep(2);

        self::assertNull($store->context($pair['access_token']));

        $newPair = $store->refreshAccessToken($pair['refresh_token'], 60, 60);

        self::assertIsArray($newPair);
        self::assertSame('admin', $newPair['username']);
        self::assertNotSame($pair['access_token'], $newPair['access_token']);
        self::assertNotSame($pair['refresh_token'], $newPair['refresh_token']);
        self::assertInstanceOf(ApiAuthContext::class, $store->context($newPair['access_token']));
        self::assertNull($store->refreshAccessToken($pair['refresh_token'], 60, 60));
    }

    /**
     * O bilhete do stream serve uma ligação e mais nenhuma.
     *
     * É essa a razão de existir: como o `EventSource` não deixa pôr cabeçalhos, a credencial
     * vai no URL, e um URL fica escrito no registo de qualquer proxy pelo caminho. Se o que
     * lá vai já não abre nada depois da primeira utilização, ter escapado deixa de importar.
     */
    public function testAStreamTicketOpensExactlyOneStream(): void
    {
        $store = new ApiTokenStore(new ExpiringRedisClientForApiTokenStoreTest(), 'test:api-tokens');
        $context = new ApiAuthContext(7, 'operador', ApiAuthContext::ROLE_LICENSE_CLIENT, 2103, 4, 9, 'hitcare');

        $ticket = $store->issueStreamTicket($context, 30);
        self::assertSame(30, $ticket['expires_in']);

        $resolved = $store->consumeStreamTicket($ticket['ticket']);
        self::assertInstanceOf(ApiAuthContext::class, $resolved);
        self::assertSame('operador', $resolved->username);
        self::assertSame(ApiAuthContext::ROLE_LICENSE_CLIENT, $resolved->role);
        self::assertSame(2103, $resolved->licenseId);
        self::assertSame('hitcare', $resolved->company);

        self::assertNull(
            $store->consumeStreamTicket($ticket['ticket']),
            'o segundo uso do mesmo bilhete não pode abrir nada'
        );
    }

    /** Um bilhete não é um token: não pode servir de portador para o resto da API. */
    public function testAStreamTicketIsNotAcceptedAsABearerToken(): void
    {
        $store = new ApiTokenStore(new ExpiringRedisClientForApiTokenStoreTest(), 'test:api-tokens');
        $ticket = $store->issueStreamTicket(new ApiAuthContext(1, 'admin', ApiAuthContext::ROLE_HUB_ADMIN), 30);

        self::assertNull($store->context($ticket['ticket']));
        self::assertFalse($store->validate($ticket['ticket']));
    }

    /** E o inverso: um token de acesso não se gasta como se fosse um bilhete. */
    public function testAnAccessTokenIsNotAcceptedAsAStreamTicket(): void
    {
        $store = new ApiTokenStore(new ExpiringRedisClientForApiTokenStoreTest(), 'test:api-tokens');
        $issued = $store->issue('admin', ApiAuthContext::ROLE_HUB_ADMIN, 60);

        self::assertNull($store->consumeStreamTicket($issued['access_token']));
        // E continua a valer como token de acesso: a tentativa não o pode ter queimado.
        self::assertInstanceOf(ApiAuthContext::class, $store->context($issued['access_token']));
    }

    public function testAnExpiredStreamTicketOpensNothing(): void
    {
        $store = new ApiTokenStore(new ExpiringRedisClientForApiTokenStoreTest(), 'test:api-tokens');
        $ticket = $store->issueStreamTicket(new ApiAuthContext(1, 'admin', ApiAuthContext::ROLE_HUB_ADMIN), 1);

        sleep(2);

        self::assertNull($store->consumeStreamTicket($ticket['ticket']));
    }
}

final class ExpiringRedisClientForApiTokenStoreTest implements ClientInterface
{
    /** @var array<string, string> */
    private array $strings = [];

    /** @var array<string, int> */
    private array $expiresAt = [];

    public function getCommandFactory()
    {
        throw new \BadMethodCallException('Not implemented');
    }

    public function getOptions()
    {
        throw new \BadMethodCallException('Not implemented');
    }

    public function connect()
    {
    }

    public function disconnect()
    {
    }

    public function getConnection()
    {
        throw new \BadMethodCallException('Not implemented');
    }

    public function createCommand($method, $arguments = [])
    {
        throw new \BadMethodCallException('Not implemented');
    }

    public function executeCommand(CommandInterface $command)
    {
        throw new \BadMethodCallException('Not implemented');
    }

    public function pipeline(callable $callback): void
    {
        $callback($this);
    }

    public function __call($method, $arguments)
    {
        return match (strtolower((string)$method)) {
            'setex' => $this->setex((string)$arguments[0], (int)$arguments[1], (string)$arguments[2]),
            'get' => $this->get((string)$arguments[0]),
            'del' => $this->del($arguments[0]),
            default => throw new \BadMethodCallException("Redis method {$method} not implemented"),
        };
    }

    private function setex(string $key, int $seconds, string $value): string
    {
        $this->strings[$key] = $value;
        $this->expiresAt[$key] = time() + max(1, $seconds);

        return 'OK';
    }

    private function get(string $key): ?string
    {
        if (isset($this->expiresAt[$key]) && $this->expiresAt[$key] <= time()) {
            unset($this->strings[$key], $this->expiresAt[$key]);
            return null;
        }

        return $this->strings[$key] ?? null;
    }

    private function del($keys): int
    {
        $keys = is_array($keys) ? $keys : [$keys];
        $deleted = 0;

        foreach ($keys as $key) {
            $key = (string)$key;
            if (isset($this->strings[$key])) {
                unset($this->strings[$key], $this->expiresAt[$key]);
                $deleted++;
            }
        }

        return $deleted;
    }
}
