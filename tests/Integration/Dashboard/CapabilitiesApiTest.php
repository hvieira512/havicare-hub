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

    private function makeApi(): CapabilityService
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());

        return new CapabilityService($db);
    }
}
