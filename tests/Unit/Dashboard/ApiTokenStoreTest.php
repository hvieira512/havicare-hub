<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use Hub\Api\Auth\ApiAuthContext;
use Hub\Api\Auth\ApiTokenStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\InMemoryRedisClient;

final class ApiTokenStoreTest extends TestCase
{
    public function testARefreshTokenIsNotAnAccessTokenAndIsConsumedOnce(): void
    {
        $store = new ApiTokenStore(new InMemoryRedisClient());
        $pair = $store->issueTokenPair('admin', ApiAuthContext::ROLE_HUB_ADMIN, 60, 60);

        self::assertSame('admin', $pair['username']);
        self::assertInstanceOf(ApiAuthContext::class, $store->context($pair['access_token']));
        self::assertNull($store->context($pair['refresh_token']));

        $context = $store->consumeRefreshToken($pair['refresh_token']);
        self::assertInstanceOf(ApiAuthContext::class, $context);
        self::assertSame('admin', $context->username);
        self::assertNull($store->consumeRefreshToken($pair['refresh_token']));
    }
}
