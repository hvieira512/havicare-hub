<?php

declare(strict_types=1);

namespace Tests\Integration\Dashboard;

use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
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
            ['Telemetria', 'Saúde', 'Contactos', 'Alarmes e alertas', 'Lembretes', 'Sistema']
        );
        self::assertContains('heart_rate', array_column($response['data'], 'key'));
    }

    /**
     * O ecrã das capacidades decide pelo `isTelemetry`, que não é coluna: sai de
     * `section = 'telemetry'` na consulta.
     */
    public function testTelemetryFlagFollowsTheSection(): void
    {
        $api = $this->makeApi();

        $porChave = [];
        foreach ($api->list('deviceType=watch')['data'] ?? [] as $row) {
            $porChave[(string)($row['key'] ?? '')] = $row;
        }

        self::assertArrayHasKey('heart_rate', $porChave);
        self::assertSame('telemetry', $porChave['heart_rate']['section'] ?? null);
        self::assertTrue($porChave['heart_rate']['isTelemetry'] ?? false);

        self::assertArrayHasKey('alarm_clock', $porChave);
        self::assertSame('reminders', $porChave['alarm_clock']['section'] ?? null);
        self::assertFalse($porChave['alarm_clock']['isTelemetry'] ?? true);

        foreach ($porChave as $key => $row) {
            self::assertSame(
                ($row['section'] ?? '') === 'telemetry',
                (bool)($row['isTelemetry'] ?? false),
                "o isTelemetry de {$key} não acompanha a secção",
            );
        }
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
