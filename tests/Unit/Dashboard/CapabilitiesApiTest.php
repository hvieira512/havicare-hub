<?php

namespace Tests\Unit\Dashboard;

use Hub\Api\Repository\ApiDataAccess;
use Hub\Api\Services\CapabilityService;
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
        self::assertContains(
            $response['data'][0]['sectionLabel'] ?? null,
            ['Telemetria', 'Saúde', 'Contactos', 'Alarmes', 'Sistema']
        );
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

    public function testListReturnsVoerkaNcsPagerCallCapability(): void
    {
        $api = $this->makeApi();

        $response = $api->list('deviceType=ncs');
        $keys = array_column($response['data'] ?? [], 'key');

        self::assertContains('pager_call', $keys);
        $pagerCall = null;
        foreach ($response['data'] ?? [] as $entry) {
            if (($entry['key'] ?? '') === 'pager_call') {
                $pagerCall = $entry;
                break;
            }
        }

        self::assertIsArray($pagerCall);
        self::assertSame('ncs', $pagerCall['deviceType'] ?? null);
        self::assertSame('alarms', $pagerCall['section'] ?? null);
        self::assertSame('Chamada de enfermagem', $pagerCall['label'] ?? null);
        self::assertTrue($pagerCall['isEvent'] ?? false);
        self::assertFalse($pagerCall['isConfigurable'] ?? true);
        self::assertFalse($pagerCall['isRequestable'] ?? true);
    }

    private function makeApi(): CapabilityService
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());

        return new CapabilityService($db);
    }
}
