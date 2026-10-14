<?php

declare(strict_types=1);

namespace Tests\Unit\Api\Http;

use Hub\Api\Auth\ApiAuthContext;
use Hub\Api\Auth\RouteAccessPolicy;
use PHPUnit\Framework\TestCase;

final class RouteAccessPolicyTest extends TestCase
{
    /** O ecrã de configuração de um integrador desenha-se a partir do catálogo, como o da dashboard. */
    public function testALicenseClientReadsTheConfigCatalog(): void
    {
        $client = new ApiAuthContext(1, 'havicare', ApiAuthContext::ROLE_LICENSE_CLIENT, 7, 7, 3, 'Havicare');

        self::assertTrue(
            (new RouteAccessPolicy())->allows($client, 'GET', '/api/protocols/{protocol}/config-catalog')
        );
    }
}
