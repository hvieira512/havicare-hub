<?php

namespace Hub\Api\Services;

use Hub\Api\Http\CollectionQuery;
use Hub\Api\Http\CollectionResponder;
use Hub\Api\Http\DevicePresentation;
use Hub\Api\Http\DeviceCollectionFilter;
use Hub\Api\Http\DeviceResponseCompactor;
use Hub\Command\DeviceConfigurationCatalog;
use Hub\Api\Auth\ApiAuthContext;
use Hub\Api\Repository\ApiDataAccess;
use Hub\Domain\Capability\CapabilityHelpers;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\Capability\CapabilityRegistry;
use Hub\Dashboard\DashboardStoreContract;
use Hub\Dashboard\DeviceUpdateNotifier;
use Hub\Domain\DeviceMetadata;
use Hub\DeviceHubServer;
use Hub\Log\Logger;
use Hub\PendingDownlinkQueue;
use Hub\Registry\Whitelist;

class DeviceService
{
    use CapabilityHelpers;

    private const DEFAULT_COLLECTION_LIMIT = 20;

    private CollectionQuery $query;
    private CollectionResponder $collection;
    private DeviceCollectionFilter $deviceFilter;
    private DevicePresentation $presentation;
    private DeviceResponseCompactor $responseCompactor;
    private CapabilityRegistry $capabilityRegistry;
    private DeviceConfigurationUpdateService $configurationUpdates;
    private DeviceConfigurationQueryService $configurationQueries;
    private DeviceAssociationService $associations;
    private DeviceCapabilityPresenter $capabilities;
    private DeviceDirectory $directory;
    private DeviceFeatureRequestService $featureRequests;

    public function __construct(
        private DashboardStoreContract $store,
        private Whitelist $whitelist,
        private DeviceHubServer $hub,
        private ?PendingDownlinkQueue $downlinkQueue,
        private ApiDataAccess $db,
        ?CollectionQuery $query = null,
        ?CollectionResponder $collection = null,
        ?DeviceCollectionFilter $deviceFilter = null,
        ?DevicePresentation $presentation = null,
        ?CapabilityRegistry $capabilityRegistry = null,
        ?DeviceConfigurationUpdateService $configurationUpdates = null,
        ?DeviceConfigurationQueryService $configurationQueries = null,
        ?DeviceResponseCompactor $responseCompactor = null,
    ) {
        $this->query = $query ?? new CollectionQuery();
        $this->collection = $collection ?? new CollectionResponder();
        $this->deviceFilter = $deviceFilter ?? new DeviceCollectionFilter();
        $this->presentation = $presentation ?? new DevicePresentation();
        $this->responseCompactor = $responseCompactor ?? new DeviceResponseCompactor();
        $this->capabilityRegistry = $capabilityRegistry ?? new CapabilityRegistry();
        $this->configurationUpdates = $configurationUpdates ?? new DeviceConfigurationUpdateService(
            $this->store,
            $this->hub,
            $this->db,
            $this->capabilityRegistry,
        );
        $this->configurationQueries = $configurationQueries ?? new DeviceConfigurationQueryService(
            $this->db,
            $this->capabilityRegistry,
        );
        $this->associations = new DeviceAssociationService($this->store, $this->whitelist, $this->db);
        // Built here rather than injected: it is a projection of the same
        // registry and database this service already holds.
        $this->capabilities = new DeviceCapabilityPresenter($this->capabilityRegistry, $this->db);
        $this->directory = new DeviceDirectory($this->store, $this->whitelist, $this->db);
        $this->featureRequests = new DeviceFeatureRequestService(
            $this->store,
            $this->whitelist,
            $this->hub,
            $this->db,
            $this->capabilityRegistry,
            $this->capabilities,
            $this->directory,
        );
    }

    public function list(string $query = '', ?ApiAuthContext $auth = null, string $baseUrl = 'http://localhost:8081'): array
    {
        $params = $this->query->params($query);
        $page = $this->query->page($params);
        $limit = $this->query->limit($params, 5);
        $filters = [
            'deviceType' => $this->query->filter($params, 'deviceType'),
            'licenseId' => $this->query->filter($params, 'licenseId'),
            'company' => $this->query->filter($params, 'company'),
            'supplier' => $this->query->filter($params, 'supplier'),
            'model' => $this->query->filter($params, 'model'),
            'q' => $this->query->filter($params, 'q', ''),
        ];
        $licenseScope = $auth !== null && !$auth->isAdmin() ? $auth->licenseId : null;
        $companyScope = $auth !== null && !$auth->isAdmin() ? $auth->company : null;
        $result = $this->db->whitelist->listPage($filters, $page, $limit, $licenseScope, $companyScope);
        $runtimeStates = $this->store->runtimeStates(array_map(
            static fn (array $device): string => (string)($device['imei'] ?? ''),
            $result['items']
        ));
        $items = array_map(
            fn (array $device): array => $this->presentation->attachImage(
                $this->directory->overlayRuntimeState($device, $runtimeStates),
                $this->directory->modelForSupplierAndName(
                    (string)($device['supplier'] ?? ''),
                    (string)($device['model'] ?? '')
                ),
                $baseUrl
            ),
            $result['items']
        );
        $totalPages = max(1, (int)ceil(((int)$result['total']) / max(1, $limit)));

        return [
            'data' => $items,
            'pagination' => [
                'limit' => $limit,
                'page' => min(max(1, $page), $totalPages),
                'total_pages' => $totalPages,
                'total' => (int)$result['total'],
            ],
            'filters' => [
                'applied' => $filters,
                'available' => $result['available'],
            ],
        ];
    }

    public function show(string $imei, ?ApiAuthContext $auth = null, string $baseUrl = 'http://localhost:8081'): array
    {
        $device = $this->directory->deviceSnapshot($imei);
        if (!$this->directory->canAccessDevice($imei, $auth, $device)) {
            return ['error' => ['code' => 'not_found', 'message' => 'Device was not found']];
        }
        $protocol = (string)($device['protocol'] ?? $this->directory->protocolForModel((string)($device['supplier'] ?? ''), (string)($device['model'] ?? '')));
        $modelRow = $this->directory->modelForDevice($device);
        $configRows = $this->db->deviceConfigurations->allForImei($imei);
        $model = null;
        if ($modelRow !== null) {
            $model = [
                'supplier' => (string)($device['supplier'] ?? ''),
                'internalModel' => (string)($modelRow['internal_model'] ?? ''),
                'commercialName' => (string)($modelRow['commercial_name'] ?? ''),
                'deviceType' => (string)($modelRow['device_type'] ?? ''),
                'image' => $this->presentation->modelImage($modelRow, $baseUrl),
            ];
        }

        $device = array_diff_key($device, array_flip([
            'supplier', 'model', 'deviceType', 'protocol', 'transport', 'lastConnectionId',
        ]));
        $lifecycle = $this->configurationLifecycle($imei, $modelRow, $protocol, $configRows);

        return $this->responseCompactor->compact([
            'device' => $device,
            'model' => $model,
            'configuration' => [
                'supported' => count(DeviceConfigurationCatalog::configsForProtocol($protocol)),
                'stored' => count($configRows),
            ],
            'configurations' => $this->configuration($imei),
            'effectiveConfigurations' => $lifecycle['effectiveConfigurations'],
            'configurationSync' => $lifecycle['configurationSync'],
            'capabilities' => $this->capabilities->deviceCapabilities($modelRow, $protocol, $configRows),
            'enabledCapabilityKeys' => $modelRow !== null
                ? $this->db->modelCapabilities->enabledFeaturesForModelId((int)($modelRow['id'] ?? 0))
                : CapabilityCatalog::keysForProtocol($protocol),
            'linkedDevices' => $this->db->gatewayDeviceLinks->forDevice($imei),
        ]);
    }

    public function links(string $imei, ?ApiAuthContext $auth = null): array
    {
        if (!$this->directory->canAccessDevice($imei, $auth)) {
            return ['error' => ['code' => 'not_found', 'message' => 'Device was not found']];
        }
        return ['data' => $this->db->gatewayDeviceLinks->forDevice($imei)];
    }

    public function createLink(string $imei, string $linkedImei, ?ApiAuthContext $auth = null): array
    {
        $validation = $this->validateGatewayLink($imei, $linkedImei, $auth);
        if (isset($validation['error'])) {
            return $validation;
        }
        $this->db->gatewayDeviceLinks->upsert($imei, $linkedImei);
        return ['status' => 'ok', 'gatewayDeviceKey' => $imei, 'linkedDeviceKey' => $linkedImei];
    }

    public function deleteLink(string $imei, string $linkedImei, ?ApiAuthContext $auth = null): array
    {
        $validation = $this->validateGatewayLink($imei, $linkedImei, $auth);
        if (isset($validation['error'])) {
            return $validation;
        }
        $this->db->gatewayDeviceLinks->delete($imei, $linkedImei);
        return ['status' => 'ok', 'gatewayDeviceKey' => $imei, 'linkedDeviceKey' => $linkedImei];
    }

    private function validateGatewayLink(string $imei, string $linkedImei, ?ApiAuthContext $auth): array
    {
        $gateway = $this->whitelist->getMetadata($imei);
        $linked = $this->whitelist->getMetadata($linkedImei);
        if ($gateway === null || $linked === null || !$this->directory->canAccessDevice($imei, $auth) || !$this->directory->canAccessDevice($linkedImei, $auth)) {
            return ['error' => ['code' => 'not_found', 'message' => 'Device was not found']];
        }
        if (($gateway['deviceType'] ?? '') !== 'gateway' || ($linked['deviceType'] ?? '') !== 'diaper_sensor') {
            return ['error' => ['code' => 'invalid_link', 'message' => 'A gateway can only link to a diaper sensor']];
        }
        if (
            (string)($gateway['company'] ?? 'null') !== (string)($linked['company'] ?? 'null')
            || (string)($gateway['licenseId'] ?? '0') !== (string)($linked['licenseId'] ?? '0')
        ) {
            return ['error' => ['code' => 'invalid_link', 'message' => 'Linked devices must belong to the same company and license']];
        }
        return ['status' => 'ok'];
    }


    public function requestFeature(string $imei, string $body, ?ApiAuthContext $auth = null, string $requestId = ''): array
    {
        return $this->featureRequests->requestFeature($imei, $body, $auth, $requestId);
    }

    public function commandStatus(string $id, ?ApiAuthContext $auth = null): array
    {
        return $this->featureRequests->commandStatus($id, $auth);
    }

    public function configuration(string $imei, string $query = '', ?ApiAuthContext $auth = null): array
    {
        if (!$this->directory->canAccessDevice($imei, $auth)) {
            return ['error' => ['code' => 'not_found', 'message' => 'Device was not found']];
        }

        $device = $this->directory->deviceSnapshot($imei);
        $metadata = $this->whitelist->getMetadata($imei) ?? [];
        $protocol = (string)($device['protocol'] ?? $this->directory->protocolForModel((string)($device['supplier'] ?? ($metadata['supplier'] ?? '')), (string)($device['model'] ?? ($metadata['model'] ?? ''))));
        return $this->configurationQueries->current($imei, $protocol);
    }

    public function updateConfigurations(string $imei, string $body, ?ApiAuthContext $auth = null, string $requestId = ''): array
    {
        if (!$this->directory->canAccessDevice($imei, $auth)) {
            Logger::channel('api')->warning('API device configuration rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'error_code' => 'not_found',
            ]);
            return ['error' => ['code' => 'not_found', 'message' => 'Device was not found']];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            Logger::channel('api')->warning('API device configuration rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'error_code' => 'invalid_request',
                'reason' => 'invalid_json',
            ]);
            return ['error' => ['code' => 'invalid_request', 'message' => 'Invalid JSON']];
        }

        if (!isset($decoded['configurations']) || !is_array($decoded['configurations'])) {
            Logger::channel('api')->warning('API device configuration rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'error_code' => 'invalid_request',
                'reason' => 'missing_configurations_object',
            ]);
            return ['error' => ['code' => 'invalid_request', 'message' => 'configurations object is required']];
        }

        $device = $this->directory->deviceSnapshot($imei);
        $metadata = $this->whitelist->getMetadata($imei) ?? [];
        $supplier = (string)($device['supplier'] ?? ($metadata['supplier'] ?? ''));
        $model = (string)($device['model'] ?? ($metadata['model'] ?? ''));
        $protocol = (string)($device['protocol'] ?? $this->directory->protocolForModel($supplier, $model));
        $modelRow = $this->directory->modelForSupplierAndName($supplier, $model);
        $update = $this->configurationUpdates->update(
            $imei,
            $decoded['configurations'],
            $supplier,
            $model,
            $protocol,
            $modelRow,
            $metadata,
            $device,
            $requestId,
        );
        if (isset($update['error'])) {
            return $update;
        }
        $results = $update['results'] ?? [];

        Logger::channel('api')->info('API device configuration processed', [
            'request_id' => $requestId,
            'imei' => $imei,
            'mode' => 'configurations',
            'result_count' => count($results),
            'config_keys' => array_keys($decoded['configurations']),
        ]);

        $snapshot = $this->show($imei, $auth);

        return [
            'status' => 'ok',
            'results' => $results,
            'configurations' => $this->configuration($imei, '', $auth),
            'effectiveConfigurations' => $snapshot['effectiveConfigurations'] ?? [],
            'configurationSync' => $snapshot['configurationSync'] ?? [],
        ];
    }

    public function saveConfiguration(string $imei, string $body, ?ApiAuthContext $auth = null, string $requestId = ''): array
    {
        return $this->updateConfigurations($imei, $body, $auth, $requestId);
    }

    public function create(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['error' => ['code' => 'invalid_request', 'message' => 'Invalid JSON']];
        }
        $imei = trim((string)($decoded['imei'] ?? ''));
        $supplier = trim((string)($decoded['supplier'] ?? ''));
        $model = trim((string)($decoded['model'] ?? ''));
        $modelRecord = $this->directory->modelForSupplierAndName($supplier, $model);
        $deviceType = DeviceMetadata::normalizeDeviceType((string)($modelRecord['device_type'] ?? $decoded['deviceType'] ?? 'watch'));
        $licenseId = $this->directory->normalizeLicenseId((string)($decoded['licenseId'] ?? '0'), $deviceType);
        $simNumber = trim((string)($decoded['simNumber'] ?? ''));
        $deviceId = trim((string)($decoded['deviceId'] ?? $decoded['device_id'] ?? ''));
        $company = trim((string)($decoded['company'] ?? 'null'));
        if ($imei === '' || $supplier === '' || $model === '') {
            return ['error' => ['code' => 'invalid_request', 'message' => 'imei, supplier, and model are required']];
        }
        if ($modelRecord === null) {
            return ['error' => ['code' => 'model_not_found', 'message' => 'Model does not exist for this supplier']];
        }
        if ($this->whitelist->getMetadata($imei) !== null) {
            return ['error' => ['code' => 'device_exists', 'message' => 'Device with this IMEI already exists']];
        }
        if ($licenseId === 0 && $deviceType !== 'watch') {
            return ['error' => ['code' => 'invalid_request', 'message' => 'licenseId is required for non-watch devices']];
        }
        $deviceId = $this->directory->normalizeDeviceId($imei, $supplier, $model, $deviceType, $deviceId);
        $this->whitelist->register($imei, $supplier, $model, $deviceType, $licenseId, $simNumber, $deviceId, $company);
        $this->store->registerDevice($imei, $supplier, $model, $deviceType, $licenseId, $simNumber, $deviceId, $company);

        return ['status' => 'ok', 'imei' => $imei];
    }

    public function update(string $imei, string $body, ?ApiAuthContext $auth = null, string $requestId = ''): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            Logger::channel('api')->warning('API device update rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'error_code' => 'invalid_request',
                'reason' => 'invalid_json',
            ]);
            return ['error' => ['code' => 'invalid_request', 'message' => 'Invalid JSON']];
        }

        if (
            isset($decoded['configurations'])
            || isset($decoded['configs'])
            || isset($decoded['capabilities'])
        ) {
            Logger::channel('api')->warning('API device update rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'error_code' => 'invalid_request',
                'reason' => 'configuration_payload_not_allowed_on_metadata_endpoint',
            ]);
            return ['error' => ['code' => 'invalid_request', 'message' => 'Use /api/devices/{imei}/configurations for device configurations']];
        }

        if ($auth !== null && !$auth->isAdmin()) {
            Logger::channel('api')->warning('API device update rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'error_code' => 'forbidden',
                'reason' => 'metadata_update_requires_admin',
            ]);
            return ['error' => ['code' => 'forbidden', 'message' => 'Forbidden']];
        }

        $newImei = trim((string)($decoded['imei'] ?? $imei));
        $supplier = trim((string)($decoded['supplier'] ?? ''));
        $model = trim((string)($decoded['model'] ?? ''));
        $modelRecord = $this->directory->modelForSupplierAndName($supplier, $model);
        $deviceType = DeviceMetadata::normalizeDeviceType((string)($modelRecord['device_type'] ?? $decoded['deviceType'] ?? 'watch'));
        $licenseId = $this->directory->normalizeLicenseId((string)($decoded['licenseId'] ?? '0'), $deviceType);
        $simNumber = trim((string)($decoded['simNumber'] ?? ''));
        $deviceId = trim((string)($decoded['deviceId'] ?? $decoded['device_id'] ?? ''));
        $company = trim((string)($decoded['company'] ?? 'null'));
        if ($newImei === '' || $supplier === '' || $model === '') {
            Logger::channel('api')->warning('API device update rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'error_code' => 'invalid_request',
                'reason' => 'missing_required_metadata_fields',
            ]);
            return ['error' => ['code' => 'invalid_request', 'message' => 'imei, supplier, and model are required']];
        }
        if ($modelRecord === null) {
            Logger::channel('api')->warning('API device update rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'error_code' => 'model_not_found',
            ]);
            return ['error' => ['code' => 'model_not_found', 'message' => 'Model does not exist for this supplier']];
        }
        if ($newImei !== $imei && $this->whitelist->getMetadata($newImei) !== null) {
            Logger::channel('api')->warning('API device update rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'new_imei' => $newImei,
                'error_code' => 'device_exists',
            ]);
            return ['error' => ['code' => 'device_exists', 'message' => 'Device with this IMEI already exists']];
        }
        if ($licenseId === 0 && $deviceType !== 'watch') {
            Logger::channel('api')->warning('API device update rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'error_code' => 'invalid_request',
                'reason' => 'missing_license_id',
            ]);
            return ['error' => ['code' => 'invalid_request', 'message' => 'licenseId is required for non-watch devices']];
        }
        $deviceId = $this->directory->normalizeDeviceId($newImei, $supplier, $model, $deviceType, $deviceId);
        if ($newImei !== $imei) {
            $this->whitelist->unregister($imei);
            $this->store->deleteDevice($imei);
        }
        $this->whitelist->register($newImei, $supplier, $model, $deviceType, $licenseId, $simNumber, $deviceId, $company);
        $this->store->registerDevice($newImei, $supplier, $model, $deviceType, $licenseId, $simNumber, $deviceId, $company);

        Logger::channel('api')->info('API device metadata updated', [
            'request_id' => $requestId,
            'imei' => $imei,
            'new_imei' => $newImei,
            'supplier' => $supplier,
            'model' => $model,
            'license_id' => $licenseId,
            'company' => $company,
        ]);

        return ['status' => 'ok', 'imei' => $newImei];
    }

    public function patchAssociation(string $imei, string $body, ?ApiAuthContext $auth = null): array
    {
        return $this->associations->associate($imei, $body, $auth);
    }

    public function deleteAssociation(string $imei, ?ApiAuthContext $auth = null): array
    {
        return $this->associations->remove($imei, $auth);
    }

    public function delete(string $imei): array
    {
        $this->whitelist->unregister($imei);
        $this->store->deleteDevice($imei);

        return ['status' => 'ok', 'imei' => $imei];
    }

    /**
     * The stream subscribes here to learn when recent() would return something
     * new, instead of re-reading it on a timer.
     */
    public function updates(): DeviceUpdateNotifier
    {
        return $this->store->updates();
    }

    public function recent(string $imei, ?ApiAuthContext $auth = null): array
    {
        if (!$this->directory->canAccessDevice($imei, $auth)) {
            return ['error' => ['code' => 'not_found', 'message' => 'Device was not found']];
        }

        return [
            'telemetry' => $this->store->recent($imei, 'telemetry'),
            'events' => $this->store->recent($imei, 'events'),
            'commands' => $this->store->commands($imei),
        ];
    }

    private function transportPending(string $imei): array
    {
        if ($this->downlinkQueue === null) {
            return [];
        }

        return array_map(static fn($item): array => [
            'dedupeKey' => $item->dedupeKey,
            'command' => $item->command,
            'queuedAt' => gmdate('Y-m-d\\TH:i:s\\Z', $item->queuedAt),
            'expiresAt' => gmdate('Y-m-d\\TH:i:s\\Z', $item->expiresAt),
        ], $this->downlinkQueue->pendingFor($imei));
    }

    /**
     * @param list<array<string,mixed>> $configRows
     * @return array{effectiveConfigurations:array<string,mixed>,configurationSync:array<string,mixed>}
     */
    private function configurationLifecycle(
        string $imei,
        ?array $model,
        string $protocol,
        array $configRows
    ): array {
        $changes = $this->db->configurationLifecycle->currentForImei($imei);
        $entries = [];
        $effective = [];
        foreach ($changes as $change) {
            $key = (string)$change['config_key'];
            $section = CapabilityCatalog::sectionForCapabilityKey($key) ?? 'settings_system';
            $operations = array_map(static fn(array $operation): array => [
                'operationId' => (string)$operation['operation_id'],
                'nativeKey' => (string)$operation['native_key'],
                'command' => (string)$operation['native_type'],
                'confirmationMode' => (string)$operation['confirmation_mode'],
                'deliveryStatus' => (string)$operation['delivery_status'],
                'error' => (string)$operation['error_code'],
                'attempts' => (int)$operation['attempts'],
                'maxAttempts' => (int)$operation['max_attempts'],
                'updatedAt' => (string)$operation['updated_at'],
            ], (array)$change['operations']);
            $nativeKey = (string)($operations[0]['nativeKey'] ?? '');
            $desiredValue = $change['desired_payload'];
            if (is_array($desiredValue)) {
                $desiredValue = $this->capabilities->normalizeCapabilityValue(
                    $protocol,
                    $key,
                    $nativeKey,
                    $desiredValue
                );
            }
            $effectiveValue = $change['effective_payload'];
            if (is_array($effectiveValue)) {
                $effectiveValue = $this->capabilities->normalizeCapabilityValue(
                    $protocol,
                    $key,
                    $nativeKey,
                    $effectiveValue
                );
                $effective[$key] = $effectiveValue;
            }
            $entries[$section][$key] = [
                'status' => (string)$change['sync_status'],
                'changeId' => (string)$change['change_id'],
                'desiredRevision' => (int)$change['desired_revision'],
                'desired' => $desiredValue,
                'effective' => $effectiveValue,
                'hasUnconfirmedChanges' => (string)$change['sync_status'] !== 'confirmed',
                'desiredUpdatedAt' => (string)$change['created_at'],
                'confirmedAt' => (string)$change['confirmed_at'],
                'operations' => $operations,
            ];
        }

        // Rows written before the lifecycle migration remain readable until the
        // next PATCH creates their first revision.
        $legacyPending = $this->pendingConfiguration($model, $protocol, $configRows);
        $desired = $this->configuration($imei);
        foreach ($desired as $key => $value) {
            $section = CapabilityCatalog::sectionForCapabilityKey((string)$key) ?? 'settings_system';
            if (isset($entries[$section][$key])) {
                continue;
            }
            $pending = $legacyPending[$section][$key] ?? null;
            $status = is_array($pending) ? (string)($pending['status'] ?? 'awaiting_confirmation') : 'confirmed';
            $legacyEffective = $status === 'confirmed' || $status === 'applied' ? $value : null;
            if ($legacyEffective !== null) {
                $effective[$key] = $legacyEffective;
            }
            $entries[$section][$key] = [
                'status' => $status === 'applied' ? 'confirmed' : $status,
                'changeId' => '',
                'desiredRevision' => 0,
                'desired' => $value,
                'effective' => $legacyEffective,
                'hasUnconfirmedChanges' => $legacyEffective === null,
                'operations' => [],
            ];
        }

        $flat = [];
        foreach ($entries as $section) {
            array_push($flat, ...array_values($section));
        }
        $pendingCount = count(array_filter($flat, static fn(array $entry): bool =>
            !in_array($entry['status'], ['confirmed', 'failed'], true)
        ));
        $failedCount = count(array_filter($flat, static fn(array $entry): bool =>
            $entry['status'] === 'failed'
        ));

        return [
            'effectiveConfigurations' => $effective,
            'configurationSync' => [
                'status' => $failedCount > 0 ? 'failed' : ($pendingCount > 0 ? 'pending' : 'confirmed'),
                'hasUnconfirmedChanges' => $pendingCount > 0 || $failedCount > 0,
                'pendingCount' => $pendingCount,
                'failedCount' => $failedCount,
                'entries' => $entries,
            ],
        ];
    }

    private function pendingConfiguration(?array $model, string $protocol, array $configRows): array
    {
        $desiredCapabilities = $this->capabilities->deviceCapabilitiesFromPayloadKey($model, $protocol, $configRows, 'desired_payload', false);
        $reportedCapabilities = $this->capabilities->deviceCapabilitiesFromPayloadKey(
            $model,
            $protocol,
            $this->configurationValueReportRows($configRows),
            'reported_payload',
            false
        );
        $desiredValues = $this->capabilities->flattenWritableCapabilities($protocol, $desiredCapabilities);
        $reportedValues = $this->capabilities->flattenWritableCapabilities($protocol, $reportedCapabilities);
        $rowMeta = $this->capabilities->genericCapabilityRowMeta($configRows);
        $pending = [];

        foreach ($desiredValues as $path => $desiredValue) {
            $reportedExists = array_key_exists($path, $reportedValues);
            $reportedValue = $reportedExists ? $reportedValues[$path] : null;
            if ($reportedExists && $this->capabilities->capabilityValuesEqual($desiredValue, $reportedValue)) {
                continue;
            }

            [$section, $key] = explode('.', $path, 2);
            $meta = $rowMeta[$key] ?? [];
            $lastStatus = (string)($meta['last_status'] ?? '');
            $pending[$section][$key] = [
                'status' => $this->capabilities->pendingStatus($lastStatus, $reportedExists),
                'error' => $this->capabilities->pendingFailureCode($lastStatus),
                'desired' => $desiredValue,
                'reported' => $reportedValue,
                'updatedAt' => $meta['updated_at'] ?? '',
                'lastCommandId' => $meta['last_command_id'] ?? '',
            ];
        }

        return $pending;
    }

    /**
     * Delivery acknowledgements are persisted in reported_payload so their
     * timestamp and native response remain inspectable. They are not reported
     * configuration values and must not be compared with the desired payload.
     *
     * @param list<array<string, mixed>> $configRows
     * @return list<array<string, mixed>>
     */
    private function configurationValueReportRows(array $configRows): array
    {
        return array_map(function (array $row): array {
            $reported = is_array($row['reported_payload'] ?? null)
                ? $row['reported_payload']
                : [];
            if ($this->isAcknowledgementOnlyConfigurationReport($reported)) {
                $row['reported_payload'] = [];
            }

            return $row;
        }, $configRows);
    }

    /**
     * @param array<string, mixed> $reported
     */
    private function isAcknowledgementOnlyConfigurationReport(array $reported): bool
    {
        if ((string)($reported['type'] ?? '') !== 'device_config') {
            return false;
        }

        $data = $reported['data'] ?? null;
        if (!is_array($data) || array_diff(array_keys($data), ['status']) !== []) {
            return false;
        }

        return in_array(strtolower(trim((string)($data['status'] ?? ''))), [
            'ok',
            'success',
            'acked',
        ], true);
    }

}
