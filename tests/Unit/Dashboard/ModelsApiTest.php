<?php

namespace Tests\Unit\Dashboard;

use GuzzleHttp\Psr7\ServerRequest;
use Hub\Api\Routes\Models;
use Hub\Dashboard\DashboardDataAccess;
use Tests\Support\MysqlDashboardTestCase;

final class ModelsApiTest extends MysqlDashboardTestCase
{
    public function testShowReturnsGroupedGenericCapabilitiesWithoutLegacyFields(): void
    {
        [$api, $db] = $this->makeApi();
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $response = $api->show((int)$model['id']);

        self::assertArrayNotHasKey('enabledCapabilities', $response);
        self::assertArrayNotHasKey('configurationCatalog', $response);
        self::assertIsArray($response['capabilities'] ?? null);
        self::assertTrue($response['capabilities']['telemetry']['heart_rate'] ?? false);
        self::assertTrue($response['capabilities']['telemetry']['location'] ?? false);
        self::assertTrue($response['capabilities']['health']['auto_vitals_interval'] ?? false);
        self::assertTrue($response['capabilities']['contacts']['phonebook'] ?? false);
        self::assertFalse($response['capabilities']['settings_system']['language_timezone'] ?? true);
    }

    public function testListReturnsGroupedGenericCapabilities(): void
    {
        [$api] = $this->makeApi();

        $response = $api->list();
        $wonlex = null;
        foreach ($response['data'] ?? [] as $entry) {
            if (($entry['supplier'] ?? '') === 'Wonlex' && ($entry['internalModel'] ?? '') === 'L08 Pro') {
                $wonlex = $entry;
                break;
            }
        }

        self::assertIsArray($wonlex);
        self::assertArrayNotHasKey('enabledCapabilities', $wonlex);
        self::assertIsArray($wonlex['capabilities'] ?? null);
        self::assertTrue($wonlex['capabilities']['telemetry']['ecg'] ?? false);
        self::assertTrue($wonlex['capabilities']['health']['heart_rate_measurement_interval'] ?? false);
        self::assertTrue($wonlex['capabilities']['alarms']['alarm_clock'] ?? false);
        self::assertTrue($wonlex['capabilities']['settings_system']['location_reporting_interval'] ?? false);
    }

    public function testUpdateAcceptsGenericCapabilityKeys(): void
    {
        [$api, $db] = $this->makeApi();
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $request = (new ServerRequest('PUT', '/api/models/' . (int)$model['id']))
            ->withParsedBody([
                'supplier_id' => (int)$model['supplier_id'],
                'internalModel' => (string)$model['internal_model'],
                'commercialName' => (string)$model['commercial_name'],
                'deviceType' => (string)$model['device_type'],
                'capabilitiesConfigured' => '1',
                'capabilities' => ['heart_rate', 'phonebook', 'working_mode'],
            ]);

        $result = $api->update((int)$model['id'], $request);

        self::assertSame('ok', $result['status'] ?? null);
        self::assertSame(
            ['heart_rate', 'phonebook', 'working_mode'],
            $db->modelCapabilities->enabledFeaturesForModelId((int)$model['id'])
        );

        $updated = $api->show((int)$model['id']);
        self::assertTrue($updated['capabilities']['telemetry']['heart_rate'] ?? false);
        self::assertTrue($updated['capabilities']['contacts']['phonebook'] ?? false);
        self::assertTrue($updated['capabilities']['settings_system']['working_mode'] ?? false);
        self::assertFalse($updated['capabilities']['telemetry']['blood_pressure'] ?? true);
    }

    /**
     * @return array{0: Models, 1: DashboardDataAccess}
     */
    private function makeApi(): array
    {
        $db = DashboardDataAccess::fromDatabase($this->createDashboardDatabase());

        return [new Models($db), $db];
    }
}
