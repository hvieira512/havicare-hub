<?php

namespace Tests\Integration\Dashboard;

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

    public function testListReturnsVoerkaNcsHelpCallCapability(): void
    {
        $api = $this->makeApi();

        $response = $api->list('deviceType=ncs');
        $keys = array_column($response['data'] ?? [], 'key');

        self::assertContains('help_call', $keys);
        $helpCall = null;
        foreach ($response['data'] ?? [] as $entry) {
            if (($entry['key'] ?? '') === 'help_call') {
                $helpCall = $entry;
                break;
            }
        }

        self::assertIsArray($helpCall);
        self::assertSame('ncs', $helpCall['deviceType'] ?? null);
        self::assertSame('alarms', $helpCall['section'] ?? null);
        self::assertSame('Chamada de ajuda', $helpCall['label'] ?? null);
        self::assertTrue($helpCall['isEvent'] ?? false);
        self::assertFalse($helpCall['isConfigurable'] ?? true);
        self::assertFalse($helpCall['isRequestable'] ?? true);
    }

    private function makeApi(): CapabilityService
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());

        return new CapabilityService($db);
    }
}
