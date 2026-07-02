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

        self::assertInstanceOf(ApiAuthContext::class, $store->context($pair['access_token']));
        self::assertNull($store->context($pair['refresh_token']));

        sleep(2);

        self::assertNull($store->context($pair['access_token']));

        $newPair = $store->refreshAccessToken($pair['refresh_token'], 60, 60);

        self::assertIsArray($newPair);
        self::assertNotSame($pair['access_token'], $newPair['access_token']);
        self::assertNotSame($pair['refresh_token'], $newPair['refresh_token']);
        self::assertInstanceOf(ApiAuthContext::class, $store->context($newPair['access_token']));
        self::assertNull($store->refreshAccessToken($pair['refresh_token'], 60, 60));
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
