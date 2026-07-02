<?php

namespace Tests\Unit\Dashboard;

use GuzzleHttp\Psr7\ServerRequest;
use Hub\Api\Repository\ApiDataAccess;
use Hub\Api\Services\ModelService;
use Psr\Http\Message\ServerRequestInterface;
use Tests\Support\MysqlDashboardTestCase;

final class ModelsApiTest extends MysqlDashboardTestCase
{
    private function request(): ServerRequestInterface
    {
        return new ServerRequest('GET', 'http://localhost/api/models');
    }

    public function testShowReturnsGroupedGenericCapabilitiesWithoutLegacyFields(): void
    {
        [$api, $db] = $this->makeApi();
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $response = $api->show((int)$model['id']);

        self::assertArrayNotHasKey('enabledCapabilities', $response);
        self::assertArrayNotHasKey('configurationCatalog', $response);
        self::assertIsArray($response['capabilities'] ?? null);
        self::assertSame((int)$model['supplier_id'], $response['supplier_id'] ?? null);
        self::assertTrue($response['capabilities']['telemetry']['heart_rate'] ?? false);
        self::assertTrue($response['capabilities']['telemetry']['location'] ?? false);
        self::assertTrue($response['capabilities']['health']['auto_vitals_interval'] ?? false);
        self::assertTrue($response['capabilities']['contacts']['phonebook'] ?? false);
        self::assertFalse($response['capabilities']['settings_system']['language_timezone'] ?? true);
    }

    public function testTemplateReturnsDerivedCapabilitiesForSupplierAndDeviceType(): void
    {
        [$api, $db] = $this->makeApi();
        $supplier = $db->suppliers->findByName('Wonlex');

        self::assertIsArray($supplier);
        $request = new ServerRequest('GET', 'http://localhost/api/models/template?supplierId=' . (int)$supplier['id'] . '&deviceType=watch');
        $response = $api->template((string)$request->getUri()->getQuery());

        self::assertSame('Wonlex', $response['supplier'] ?? null);
        self::assertSame('watch', $response['deviceType'] ?? null);
        self::assertContains('ecg', $response['enabledCapabilities'] ?? []);
        self::assertContains('hrv', $response['enabledCapabilities'] ?? []);
        self::assertContains('ppg', $response['enabledCapabilities'] ?? []);
        self::assertContains('rr_interval', $response['enabledCapabilities'] ?? []);
        self::assertTrue($response['capabilities']['telemetry']['ecg'] ?? false);
    }

    public function testListReturnsGroupedGenericCapabilities(): void
    {
        [$api] = $this->makeApi();

        $response = $api->list((string)$this->request()->getUri()->getQuery(), 'http://localhost');
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

    public function testFiltersListDeviceTypesAndSuppliersFromAssociationTable(): void
    {
        [$api] = $this->makeApi();

        $response = $api->filters();
        $groups = $response['data'] ?? [];

        self::assertCount(3, $groups);
        self::assertSame('watch', $groups[0]['deviceType'] ?? null);
        self::assertSame('ncs', $groups[1]['deviceType'] ?? null);
        self::assertSame('radar', $groups[2]['deviceType'] ?? null);
        self::assertSame(['4P Touch', 'Vivistar', 'Wonlex'], array_values(array_map(
            static fn (array $supplier): string => (string)($supplier['name'] ?? ''),
            $groups[0]['suppliers'] ?? []
        )));
        self::assertSame(['Voerka'], array_values(array_map(
            static fn (array $supplier): string => (string)($supplier['name'] ?? ''),
            $groups[1]['suppliers'] ?? []
        )));
        self::assertSame(['Qinglanst'], array_values(array_map(
            static fn (array $supplier): string => (string)($supplier['name'] ?? ''),
            $groups[2]['suppliers'] ?? []
        )));
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

    public function testCreateWithoutExplicitCapabilitiesStoresSupplierTemplateDefaults(): void
    {
        [$api, $db] = $this->makeApi();
        $supplier = $db->suppliers->findByName('4P Touch');

        self::assertIsArray($supplier);
        $request = (new ServerRequest('POST', '/api/models'))
            ->withParsedBody([
                'supplier_id' => (int)$supplier['id'],
                'internalModel' => 'D46-PLUS',
                'commercialName' => 'D46 Plus',
                'deviceType' => 'watch',
            ]);

        $result = $api->create($request);

        self::assertSame('ok', $result['status'] ?? null);
        $model = $db->models->find('4P Touch', 'D46-PLUS');
        self::assertIsArray($model);
        self::assertSame(
            [
                'auto_vitals_interval',
                'blood_pressure',
                'call_whitelist',
                'device_password',
                'fall_detection',
                'fall_sensitivity',
                'heart_rate',
                'language_timezone',
                'location',
                'location_reporting_interval',
                'low_battery_alert',
                'monitor_number',
                'pedometer_schedule',
                'remove_watch_alarm',
                'remove_watch_sms_alert',
                'sleep_monitoring',
                'sos_contacts',
                'sos_sms_alert',
                'temperature',
                'temperature_measurement_interval',
            ],
            $db->modelCapabilities->enabledFeaturesForModelId((int)$model['id'])
        );
    }

    public function testUpdateWithoutExplicitCapabilitiesPreservesExistingCapabilities(): void
    {
        [$api, $db] = $this->makeApi();
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['heart_rate', 'phonebook']);

        $request = (new ServerRequest('PUT', '/api/models/' . (int)$model['id']))
            ->withParsedBody([
                'supplier_id' => (int)$model['supplier_id'],
                'internalModel' => (string)$model['internal_model'],
                'commercialName' => 'Vivistar L08 Pro Renamed',
                'deviceType' => (string)$model['device_type'],
            ]);

        $result = $api->update((int)$model['id'], $request);

        self::assertSame('ok', $result['status'] ?? null);
        self::assertSame(
            ['heart_rate', 'phonebook'],
            $db->modelCapabilities->enabledFeaturesForModelId((int)$model['id'])
        );
    }

    public function testUpdateRejectsCapabilitiesOutsideDeviceTypeCatalog(): void
    {
        [$api, $db] = $this->makeApi();
        $model = $db->models->find('Qinglanst', 'RD-V1');

        self::assertIsArray($model);
        $request = (new ServerRequest('PUT', '/api/models/' . (int)$model['id']))
            ->withParsedBody([
                'supplier_id' => (int)$model['supplier_id'],
                'internalModel' => (string)$model['internal_model'],
                'commercialName' => (string)$model['commercial_name'],
                'deviceType' => (string)$model['device_type'],
                'capabilitiesConfigured' => '1',
                'capabilities' => ['heart_rate'],
            ]);

        $result = $api->update((int)$model['id'], $request);

        self::assertSame('unsupported_capability', $result['error']['code'] ?? null);
    }

    /**
     * @return array{0: ModelService, 1: ApiDataAccess}
     */
    private function makeApi(): array
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());

        return [new ModelService($db), $db];
    }
}
