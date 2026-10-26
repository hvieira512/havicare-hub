<?php

declare(strict_types=1);

namespace Tests\Unit\Api\Auth;

use Hub\Api\Auth\ApiAuthContext;
use Hub\Api\Auth\ApiTokenStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\InMemoryRedisClient;

/** Um cliente que inicia sessão a cada pedido não acumula tokens sem fim: valem as 20 sessões mais recentes. */
final class ApiTokenCapTest extends TestCase
{
    public function testOnlyTheTwentyNewestSessionsOfAUserStayValid(): void
    {
        $store = new ApiTokenStore(new InMemoryRedisClient());
        $user = new ApiAuthContext(20, 'havicare-dev-hugo', ApiAuthContext::ROLE_LICENSE_CLIENT, 22, 3, 3, 'havicare');
        $other = new ApiAuthContext(4, 'hugo-vieira', ApiAuthContext::ROLE_HUB_ADMIN);

        $others = $store->issueTokenPair($other, 3600, 86400);
        $sessions = [];
        for ($i = 0; $i < 25; $i++) {
            $sessions[] = $store->issueTokenPair($user, 3600, 86400);
        }

        self::assertNull($store->context($sessions[0]['access_token']));
        self::assertNull($store->consumeRefreshToken($sessions[4]['refresh_token']));
        self::assertNotNull($store->context($sessions[5]['access_token']));
        self::assertNotNull($store->context($sessions[24]['access_token']));
        self::assertNotNull($store->context($others['access_token']), 'o limite é por utilizador');
    }
}
