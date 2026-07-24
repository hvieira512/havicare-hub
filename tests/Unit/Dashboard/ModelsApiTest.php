<?php

namespace Tests\Unit\Dashboard;

use GuzzleHttp\Psr7\ServerRequest;
use Hub\Api\Repository\ApiDataAccess;
use Hub\Api\Services\ModelService;
use Hub\Domain\SupplierCapabilityTemplate;
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
        self::assertTrue($response['capabilities']['contacts']['call_whitelist'] ?? false);
        self::assertFalse($response['capabilities']['settings_system']['language_timezone'] ?? false);
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
        self::assertContains('battery', $response['enabledCapabilities'] ?? []);
        self::assertContains('activity', $response['enabledCapabilities'] ?? []);
        self::assertContains('ecg', $response['enabledCapabilities'] ?? []);
        self::assertContains('hrv', $response['enabledCapabilities'] ?? []);
        self::assertContains('ppg', $response['enabledCapabilities'] ?? []);
        self::assertContains('rr_interval', $response['enabledCapabilities'] ?? []);
        self::assertContains('blood_sugar', $response['enabledCapabilities'] ?? []);
        self::assertTrue($response['capabilities']['telemetry']['ecg'] ?? false);
    }

    public function testListReturnsGroupedGenericCapabilities(): void
    {
        [$api] = $this->makeApi();

        $response = $api->list((string)$this->request()->getUri()->getQuery(), 'http://localhost');
        $wonlex = null;
        foreach ($response['data'] ?? [] as $entry) {
            if (($entry['supplier'] ?? '') === 'Wonlex' && ($entry['internalModel'] ?? '') === 'HW20PRO') {
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

    public function testDeviceTypeSuppliersModelsReturnsAbsoluteImageUrls(): void
    {
        [$api, $db] = $this->makeApi();
        $supplier = $db->suppliers->findByName('Vivistar');

        self::assertIsArray($supplier);
        $db->models->add((int)$supplier['id'], 'L08 Pro X', 'L08 Pro X', 'watch', '/model-images/example.jpg');

        $response = $api->deviceTypeSuppliersModels('http://localhost:8081');
        $watchGroup = null;
        foreach ($response['data'] ?? [] as $group) {
            if (($group['deviceType'] ?? '') === 'watch') {
                $watchGroup = $group;
                break;
            }
        }

        self::assertIsArray($watchGroup);
        $vivistar = null;
        foreach ($watchGroup['suppliers'] ?? [] as $supplierGroup) {
            if (($supplierGroup['name'] ?? '') === 'Vivistar') {
                $vivistar = $supplierGroup;
                break;
            }
        }

        self::assertIsArray($vivistar);
        $model = null;
        foreach ($vivistar['models'] ?? [] as $entry) {
            if (($entry['internalModel'] ?? '') === 'L08 Pro X') {
                $model = $entry;
                break;
            }
        }

        self::assertIsArray($model);
        self::assertSame('http://localhost:8081/model-images/example.jpg', $model['image'] ?? null);
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

    public function testBootstrapSeedsVoerkaW812AsNcsModel(): void
    {
        [, $db] = $this->makeApi();
        $model = $db->models->find('Voerka', 'W812');

        self::assertIsArray($model);
        self::assertSame('Voerka', $model['supplier_name'] ?? null);
        self::assertSame('W812', $model['internal_model'] ?? null);
        self::assertSame('W812', $model['commercial_name'] ?? null);
        self::assertSame('ncs', $model['device_type'] ?? null);
    }

    public function testBootstrapSeedsVoerkaW812PagerCallCapability(): void
    {
        [, $db] = $this->makeApi();
        $model = $db->models->find('Voerka', 'W812');

        self::assertIsArray($model);
        self::assertSame(
            ['pager_call'],
            $db->modelCapabilities->enabledFeaturesForModelId((int)$model['id'])
        );
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
                'capabilities' => ['heart_rate', 'call_whitelist', 'working_mode'],
            ]);

        $result = $api->update((int)$model['id'], $request);

        self::assertSame('ok', $result['status'] ?? null);
        $actual = $db->modelCapabilities->enabledFeaturesForModelId((int)$model['id']);
        sort($actual);
        self::assertSame(['call_whitelist', 'heart_rate', 'working_mode'], $actual);

        $updated = $api->show((int)$model['id']);
        self::assertTrue($updated['capabilities']['telemetry']['heart_rate'] ?? false);
        self::assertTrue($updated['capabilities']['contacts']['call_whitelist'] ?? false);
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
        $expected = SupplierCapabilityTemplate::keysForSupplierDeviceType('4P Touch', 'watch');
        $actual = $db->modelCapabilities->enabledFeaturesForModelId((int)$model['id']);
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual);
    }

    public function testUpdateWithoutExplicitCapabilitiesPreservesExistingCapabilities(): void
    {
        [$api, $db] = $this->makeApi();
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['heart_rate', 'call_whitelist']);

        $request = (new ServerRequest('PUT', '/api/models/' . (int)$model['id']))
            ->withParsedBody([
                'supplier_id' => (int)$model['supplier_id'],
                'internalModel' => (string)$model['internal_model'],
                'commercialName' => 'Vivistar L08 Pro Renamed',
                'deviceType' => (string)$model['device_type'],
            ]);

        $result = $api->update((int)$model['id'], $request);

        self::assertSame('ok', $result['status'] ?? null);
        $actual = $db->modelCapabilities->enabledFeaturesForModelId((int)$model['id']);
        sort($actual);
        self::assertSame(['call_whitelist', 'heart_rate'], $actual);
    }

    public function testUpdateWithoutExplicitCapabilitiesDropsUnsupportedSupplierFeatures(): void
    {
        [$api, $db] = $this->makeApi();
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['heart_rate', 'ecg']);

        $request = (new ServerRequest('PUT', '/api/models/' . (int)$model['id']))
            ->withParsedBody([
                'supplier_id' => (int)$model['supplier_id'],
                'internalModel' => (string)$model['internal_model'],
                'commercialName' => (string)$model['commercial_name'],
                'deviceType' => (string)$model['device_type'],
            ]);

        $result = $api->update((int)$model['id'], $request);

        self::assertSame('ok', $result['status'] ?? null);
        self::assertSame(
            ['heart_rate'],
            $db->modelCapabilities->enabledFeaturesForModelId((int)$model['id'])
        );
    }

    public function testUpdateRejectsCapabilitiesOutsideDeviceTypeCatalog(): void
    {
        [$api, $db] = $this->makeApi();
        $supplier = $db->suppliers->findByName('Vivistar');
        self::assertIsArray($supplier);
        $db->models->add((int)$supplier['id'], 'RADAR-TEMP', 'Radar Temp', 'radar');
        $model = $db->models->find('Vivistar', 'RADAR-TEMP');
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

    public function testUpdateRejectsCapabilitiesOutsideSupplierTemplate(): void
    {
        [$api, $db] = $this->makeApi();
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $request = (new ServerRequest('PUT', '/api/models/' . (int)$model['id']))
            ->withParsedBody([
                'supplier_id' => (int)$model['supplier_id'],
                'internalModel' => (string)$model['internal_model'],
                'commercialName' => (string)$model['commercial_name'],
                'deviceType' => (string)$model['device_type'],
                'capabilitiesConfigured' => '1',
                'capabilities' => ['heart_rate', 'ecg'],
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
