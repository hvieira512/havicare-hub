<?php

namespace Tests\Unit\Dashboard;

use Hub\Api\Routes\Capabilities;
use Hub\Dashboard\DashboardDataAccess;
use Tests\Support\MysqlDashboardTestCase;

final class CapabilitiesApiTest extends MysqlDashboardTestCase
{
    public function testListReturnsTypedCapabilityCatalogFilteredByDeviceType(): void
    {
        $api = $this->makeApi();

        $response = $api->list('deviceType=watch');

        self::assertIsArray($response['data'] ?? null);
        self::assertNotEmpty($response['data']);
        self::assertContainsOnly('array', $response['data']);
        self::assertSame('watch', $response['data'][0]['deviceType'] ?? null);
        self::assertContains('heart_rate', array_column($response['data'], 'key'));
    }

    public function testShowReturnsCapabilityDetail(): void
    {
        $api = $this->makeApi();
        $list = $api->list('deviceType=watch');
        $first = $list['data'][0] ?? null;

        self::assertIsArray($first);

        $response = $api->show((int)$first['id']);

        self::assertSame($first['id'], $response['id'] ?? null);
        self::assertSame($first['key'], $response['key'] ?? null);
        self::assertSame($first['deviceType'], $response['deviceType'] ?? null);
    }

    private function makeApi(): Capabilities
    {
        $db = DashboardDataAccess::fromDatabase($this->createDashboardDatabase());

        return new Capabilities($db);
    }
}
