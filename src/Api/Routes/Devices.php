<?php

namespace Hub\Api\Routes;

use Hub\Api\Support\CollectionResponse;
use Hub\Command\DeviceCommandCatalog;
use Hub\Command\DeviceConfigurationCatalog;
use Hub\Dashboard\ApiAuthContext;
use Hub\Dashboard\DashboardDataAccess;
use Hub\Dashboard\GenericModelCapabilityCatalog;
use Hub\Dashboard\DeviceProtocol;
use Hub\Dashboard\DashboardStore;
use Hub\Dashboard\DeviceMetadata;
use Hub\DeviceHubServer;
use Hub\Log\Logger;
use Hub\PendingDownlinkQueue;
use Hub\Registry\Whitelist;
use Psr\Http\Message\ServerRequestInterface;

final class Devices
{
    use CollectionResponse;

    private const DEFAULT_COLLECTION_LIMIT = 20;

    public function __construct(
        private DashboardStore $store,
        private Whitelist $whitelist,
        private DeviceHubServer $hub,
        private ?PendingDownlinkQueue $downlinkQueue,
        private DashboardDataAccess $db,
    ) {
    }

    public function list(string $query = '', ?ApiAuthContext $auth = null, ?ServerRequestInterface $request = null): array
    {
        $baseUrl = $this->baseUrlFromRequest($request);
        $params = $this->queryParams($query);
        $page = $this->queryPage($params);
        $limit = $this->queryLimit($params, 5);
        $filters = [
            'deviceType' => $this->queryFilter($params, 'deviceType'),
            'licenseId' => $this->queryFilter($params, 'licenseId'),
            'company' => $this->queryFilter($params, 'company'),
            'supplier' => $this->queryFilter($params, 'supplier'),
            'model' => $this->queryFilter($params, 'model'),
            'q' => $this->queryFilter($params, 'q', ''),
        ];
        $licenseScope = $auth !== null && !$auth->isAdmin() ? $auth->licenseId : null;
        $result = $this->db->whitelist->listPage($filters, $page, $limit, $licenseScope);
        if ((int)$result['total'] === 0) {
            $devices = $this->scopeDevices($this->store->devices(), $auth);
            $filtered = array_map(
                fn (array $device): array => $this->attachDeviceImage($device, $baseUrl),
                $this->filterDevices($devices, $filters)
            );
            $available = [
                'deviceType' => $this->uniqueValues(array_map(
                    static fn (array $device): string => DeviceMetadata::normalizeDeviceType((string)($device['deviceType'] ?? 'watch')),
                    $this->filterDevicesForOptions($devices, $filters, 'deviceType')
                )),
                'licenseId' => $this->uniqueValues(array_map(
                    static fn (array $device): string => DeviceMetadata::normalizeLicenseId((string)($device['licenseId'] ?? '0')),
                    $this->filterDevicesForOptions($devices, $filters, 'licenseId')
                )),
                'supplier' => $this->uniqueValues(array_map(
                    static fn (array $device): string => trim((string)($device['supplier'] ?? '')),
                    $this->filterDevicesForOptions($devices, $filters, 'supplier')
                )),
                'model' => $this->uniqueValues(array_map(
                    static fn (array $device): string => trim((string)($device['model'] ?? '')),
                    $this->filterDevicesForOptions($devices, $filters, 'model')
                )),
                'company' => $this->uniqueValues(array_map(
                    static fn (array $device): string => trim((string)($device['company'] ?? 'null')),
                    $this->filterDevicesForOptions($devices, $filters, 'company')
                )),
            ];

            return $this->collectionResponse($filtered, $page, $limit, $filters, $available);
        }

        $runtimeStates = $this->store->runtimeStates(array_map(
            static fn (array $device): string => (string)($device['imei'] ?? ''),
            $result['items']
        ));
        $items = array_map(
            fn (array $device): array => $this->attachDeviceImage(
                $this->overlayRuntimeState($device, $runtimeStates),
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

    public function show(string $imei, ?ApiAuthContext $auth = null, ?ServerRequestInterface $request = null): array
    {
        $device = $this->deviceSnapshot($imei);
        if (!$this->canAccessDevice($imei, $auth, $device)) {
            return ['error' => ['code' => 'not_found', 'message' => 'Device was not found']];
        }
        $protocol = (string)($device['protocol'] ?? $this->protocolForModel((string)($device['supplier'] ?? ''), (string)($device['model'] ?? '')));
        $modelRow = $this->modelForDevice($device);
        $configRows = $this->db->deviceConfigurations->allForImei($imei);
        $baseUrl = $this->baseUrlFromRequest($request);

        $model = null;
        if ($modelRow !== null) {
            $model = [
                'supplier' => (string)($device['supplier'] ?? ''),
                'internalModel' => (string)($modelRow['internal_model'] ?? ''),
                'commercialName' => (string)($modelRow['commercial_name'] ?? ''),
                'deviceType' => (string)($modelRow['device_type'] ?? ''),
                'image' => $this->fullModelImage((string)($modelRow['image_path'] ?? ''), $baseUrl),
            ];
        }

        $device = array_diff_key($device, array_flip([
            'supplier', 'model', 'deviceType', 'protocol', 'transport', 'lastConnectionId',
        ]));

        return [
            'device' => $device,
            'model' => $model,
            'configuration' => [
                'supported' => count(DeviceConfigurationCatalog::configsForProtocol($protocol)),
                'stored' => count($configRows),
            ],
            'configurations' => $this->configuration($imei),
            'capabilities' => $this->deviceCapabilities($modelRow, $protocol, $configRows),
            'enabledCapabilityKeys' => $modelRow !== null
                ? $this->db->modelCapabilities->enabledFeaturesForModelId((int)($modelRow['id'] ?? 0))
                : GenericModelCapabilityCatalog::keysForProtocol($protocol),
            'pending' => $this->pendingConfiguration($modelRow, $protocol, $configRows),
            'transportPending' => $this->transportPending($imei),
        ];
    }

    public function requestFeature(string $imei, string $body, ?ApiAuthContext $auth = null, string $requestId = ''): array
    {
        if (!$this->canAccessDevice($imei, $auth)) {
            Logger::channel('api')->warning('API telemetry request rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'error_code' => 'not_found',
            ]);
            return ['error' => ['code' => 'not_found', 'message' => 'Device was not found']];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            Logger::channel('api')->warning('API telemetry request rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'error_code' => 'invalid_request',
                'reason' => 'invalid_json',
            ]);
            return ['error' => ['code' => 'invalid_request', 'message' => 'Invalid JSON']];
        }

        $feature = trim((string)($decoded['feature'] ?? ''));
        $capability = trim((string)($decoded['capability'] ?? ''));
        if ($feature === '' && $capability !== '') {
            return $this->requestCapabilityAction($imei, $decoded, $auth, $requestId);
        }
        if ($feature === '') {
            Logger::channel('api')->warning('API telemetry request rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'error_code' => 'invalid_request',
                'reason' => 'missing_feature',
            ]);
            return ['error' => ['code' => 'invalid_request', 'message' => 'feature is required']];
        }

        $device = $this->deviceSnapshot($imei);
        $metadata = $this->whitelist->getMetadata($imei) ?? [];
        $supplier = (string)($device['supplier'] ?? ($metadata['supplier'] ?? ''));
        $model = (string)($device['model'] ?? ($metadata['model'] ?? ''));
        $protocol = (string)($device['protocol'] ?? $this->protocolForModel($supplier, $model));
        $modelRow = $this->modelForSupplierAndName($supplier, $model);

        $telemetrySupport = $this->telemetryCapabilities($modelRow, $protocol);
        if (!($telemetrySupport[$feature]['supported'] ?? false)) {
            Logger::channel('api')->warning('API telemetry request rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'feature' => $feature,
                'error_code' => 'unsupported_feature',
            ]);
            return ['error' => ['code' => 'unsupported_feature', 'message' => 'Feature is not supported for this device']];
        }
        if (!($telemetrySupport[$feature]['requestable'] ?? false)) {
            Logger::channel('api')->warning('API telemetry request rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'feature' => $feature,
                'error_code' => 'feature_not_requestable',
            ]);
            return ['error' => ['code' => 'feature_not_requestable', 'message' => 'Feature cannot be requested for this device']];
        }

        $result = $this->sendFeatureCommands($imei, $protocol, $feature, $metadata, $device);
        Logger::channel('api')->info('API telemetry request processed', [
            'request_id' => $requestId,
            'imei' => $imei,
            'feature' => $feature,
            'status' => $result['status'] ?? null,
            'error_code' => $result['error']['code'] ?? null,
            'command_count' => count($result['commands'] ?? []),
        ]);

        if (isset($result['error'])) {
            return $result;
        }

        $requestStatus = 'ok';
        if (($result['commands'] ?? []) !== []) {
            $statuses = array_values(array_unique(array_map(
                static fn(array $command): string => (string)($command['status'] ?? 'unknown'),
                $result['commands']
            )));
            $requestStatus = count($statuses) === 1 ? $statuses[0] : 'partial';
        }

        return [
            'status' => $requestStatus,
            'feature' => $feature,
            'commands' => $result['commands'] ?? [],
        ];
    }

    private function requestCapabilityAction(string $imei, array $decoded, ?ApiAuthContext $auth = null, string $requestId = ''): array
    {
        $capability = trim((string)($decoded['capability'] ?? ''));
        if (!$this->canAccessDevice($imei, $auth)) {
            Logger::channel('api')->warning('API capability request rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'capability' => $capability,
                'error_code' => 'not_found',
            ]);
            return ['error' => ['code' => 'not_found', 'message' => 'Device was not found']];
        }

        if ($capability === '') {
            return ['error' => ['code' => 'invalid_request', 'message' => 'capability is required']];
        }

        $device = $this->deviceSnapshot($imei);
        $metadata = $this->whitelist->getMetadata($imei) ?? [];
        $supplier = (string)($device['supplier'] ?? ($metadata['supplier'] ?? ''));
        $model = (string)($device['model'] ?? ($metadata['model'] ?? ''));
        $protocol = (string)($device['protocol'] ?? $this->protocolForModel($supplier, $model));
        $modelRow = $this->modelForSupplierAndName($supplier, $model);
        $enabled = array_flip($modelRow !== null
            ? $this->db->modelCapabilities->enabledFeaturesForModelId((int)($modelRow['id'] ?? 0))
            : GenericModelCapabilityCatalog::keysForProtocol($protocol));

        if (!isset($enabled[$capability])) {
            Logger::channel('api')->warning('API capability request rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'capability' => $capability,
                'error_code' => 'unsupported_feature',
            ]);
            return ['error' => ['code' => 'unsupported_feature', 'message' => 'Capability is not supported for this device']];
        }

        try {
            $nativeUpdates = $this->genericCapabilityToNativeUpdates($protocol, $capability, $decoded['value'] ?? null);
        } catch (\InvalidArgumentException $e) {
            Logger::channel('api')->warning('API capability request rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'capability' => $capability,
                'error_code' => 'invalid_config',
                'message' => $e->getMessage(),
            ]);
            return ['error' => ['code' => 'invalid_config', 'message' => $e->getMessage()]];
        }

        $commands = [];
        foreach ($nativeUpdates as $nativeKey => $payload) {
            $error = DeviceConfigurationCatalog::validate($protocol, $nativeKey, $payload);
            if ($error !== null) {
                return ['error' => ['code' => 'invalid_config', 'message' => $error]];
            }

            $commandPayload = DeviceConfigurationCatalog::commandPayload($protocol, $nativeKey, $payload);
            $command = $commandPayload['command'];
            $bytes = DeviceCommandCatalog::buildDownlink($protocol, $imei, $command, $commandPayload['payload'], [
                'deviceId' => (string)($metadata['deviceId'] ?? $device['deviceId'] ?? ''),
            ]);
            $id = bin2hex(random_bytes(8));
            $status = $this->hub->submitDownlink($imei, $bytes);
            $entry = DeviceConfigurationCatalog::configForProtocol($protocol, $nativeKey) ?? [];
            $record = [
                'status' => $status,
                'imei' => $imei,
                'protocol' => $protocol,
                'capability' => $capability,
                'nativeType' => $command,
                'label' => (string)($entry['label'] ?? $nativeKey),
                'expectedReplyTypes' => $entry['expectedReplyTypes'] ?? [],
                'requestedAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
            ];
            if ($status === 'sent') {
                $record['status'] = 'waiting';
                $record['sentAt'] = gmdate('Y-m-d\\TH:i:s\\Z');
            }
            if ($status === 'dropped') {
                $record['error'] = 'delivery_failed';
            }
            $this->store->recordCommand($imei, $id, $record);
            $commands[] = array_merge($record, ['id' => $id]);
        }

        $statuses = array_values(array_unique(array_map(
            static fn(array $command): string => (string)($command['status'] ?? 'unknown'),
            $commands
        )));

        return [
            'status' => count($statuses) === 1 ? $statuses[0] : 'partial',
            'capability' => $capability,
            'commands' => $commands,
        ];
    }

    private function sendFeatureCommands(string $imei, string $protocol, string $feature, array $metadata, array $device): array
    {
        $entries = DeviceCommandCatalog::commandsForFeature($protocol, $feature);
        if ($entries === []) {
            return ['error' => ['code' => 'unsupported_feature', 'message' => 'Feature is not supported for this device']];
        }

        $commands = [];
        foreach ($entries as $entry) {
            $nativeCommand = (string)($entry['command'] ?? '');
            if ($nativeCommand === '') {
                continue;
            }
            $bytes = DeviceCommandCatalog::buildDownlink($protocol, $imei, $nativeCommand, ['fields' => $entry['data'] ?? []], [
                'deviceId' => (string)($metadata['deviceId'] ?? $device['deviceId'] ?? ''),
            ]);
            $id = bin2hex(random_bytes(8));
            $status = $this->hub->submitDownlink($imei, $bytes);
            $record = [
                'status' => $status,
                'imei' => $imei,
                'protocol' => $protocol,
                'requestId' => (string)($entry['id'] ?? $nativeCommand),
                'feature' => $feature,
                'nativeType' => $nativeCommand,
                'label' => (string)($entry['label'] ?? $nativeCommand),
                'expectedReplyTypes' => $entry['expectedReplyTypes'] ?? [],
                'requestedAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
            ];
            if ($status === 'sent') {
                $record['status'] = 'waiting';
                $record['sentAt'] = gmdate('Y-m-d\\TH:i:s\\Z');
            }
            if ($status === 'dropped') {
                $record['error'] = 'delivery_failed';
            }
            $this->store->recordCommand($imei, $id, $record);
            $commands[] = array_merge($record, ['id' => $id]);
        }

        return ['status' => 'sent', 'commands' => $commands];
    }

    public function commandStatus(string $id, ?ApiAuthContext $auth = null): array
    {
        $result = $this->store->findCommand($id);
        if ($result === null) {
            return ['error' => ['code' => 'not_found', 'message' => 'Command was not found']];
        }
        $device = is_array($result['device'] ?? null) ? $result['device'] : [];
        $imei = (string)($device['imei'] ?? '');
        if ($imei === '' || !$this->canAccessDevice($imei, $auth, $device)) {
            return ['error' => ['code' => 'not_found', 'message' => 'Command was not found']];
        }

        return $result;
    }

    public function configuration(string $imei, string $query = '', ?ApiAuthContext $auth = null): array
    {
        if (!$this->canAccessDevice($imei, $auth)) {
            return ['error' => ['code' => 'not_found', 'message' => 'Device was not found']];
        }

        $configurations = [];
        foreach ($this->db->deviceConfigurations->allForImei($imei) as $row) {
            $desired = $row['desired_payload'];
            if (is_array($desired) && $desired !== []) {
                $configurations[$row['config_key']] = $desired;
            }
        }

        return $configurations;
    }

    public function saveConfiguration(string $imei, string $body, ?ApiAuthContext $auth = null, string $requestId = ''): array
    {
        if (!$this->canAccessDevice($imei, $auth)) {
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

        if (isset($decoded['capabilities'])) {
            return $this->saveGenericConfiguration($imei, $decoded, $requestId);
        }

        if (!isset($decoded['configs']) || !is_array($decoded['configs'])) {
            Logger::channel('api')->warning('API device configuration rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'error_code' => 'invalid_request',
                'reason' => 'missing_capabilities_object',
            ]);
            return ['error' => ['code' => 'invalid_request', 'message' => 'capabilities object is required']];
        }

        $supplier = trim((string)($decoded['supplier'] ?? ''));
        $model = trim((string)($decoded['model'] ?? ''));
        $results = [];
        foreach ($decoded['configs'] as $key => $payload) {
            if (!is_string($key) || !is_array($payload)) {
                Logger::channel('api')->warning('API device configuration rejected', [
                    'request_id' => $requestId,
                    'imei' => $imei,
                    'error_code' => 'invalid_config',
                    'reason' => 'invalid_config_entry',
                ]);
                return ['error' => ['code' => 'invalid_config', 'message' => 'Each config entry must be an object']];
            }
            $result = $this->persistAndApplyConfiguration($imei, $key, $payload, $supplier, $model);
            if (isset($result['error'])) {
                Logger::channel('api')->warning('API device configuration rejected', [
                    'request_id' => $requestId,
                    'imei' => $imei,
                    'config_key' => $key,
                    'error_code' => $result['error']['code'] ?? 'invalid_config',
                ]);
                return $result;
            }
            $results[] = $result;
        }

        Logger::channel('api')->info('API device configuration processed', [
            'request_id' => $requestId,
            'imei' => $imei,
            'mode' => 'configs',
            'result_count' => count($results),
            'config_keys' => array_keys($decoded['configs']),
        ]);

        return ['status' => 'ok', 'results' => $results, 'configuration' => $this->configuration($imei, '', $auth)];
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
        $modelRecord = $this->modelForSupplierAndName($supplier, $model);
        $deviceType = DeviceMetadata::normalizeDeviceType((string)($modelRecord['device_type'] ?? $decoded['deviceType'] ?? 'watch'));
        $licenseId = $this->normalizeLicenseId((string)($decoded['licenseId'] ?? '0'), $deviceType);
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
            return ['error' => ['code' => 'invalid_request', 'message' => 'licenseId is required for NCS and Radars']];
        }
        $deviceId = $this->normalizeDeviceId($imei, $supplier, $model, $deviceId);
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
        if (isset($decoded['capabilities']) || isset($decoded['configs'])) {
            return $this->saveConfiguration($imei, $body, $auth, $requestId);
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
        $modelRecord = $this->modelForSupplierAndName($supplier, $model);
        $deviceType = DeviceMetadata::normalizeDeviceType((string)($modelRecord['device_type'] ?? $decoded['deviceType'] ?? 'watch'));
        $licenseId = $this->normalizeLicenseId((string)($decoded['licenseId'] ?? '0'), $deviceType);
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
            return ['error' => ['code' => 'invalid_request', 'message' => 'licenseId is required for NCS and Radars']];
        }
        $deviceId = $this->normalizeDeviceId($newImei, $supplier, $model, $deviceId);
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
        $existing = $this->whitelist->getMetadata($imei);
        if ($existing === null) {
            return ['error' => ['code' => 'not_found', 'message' => 'Device was not found']];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['error' => ['code' => 'invalid_request', 'message' => 'Invalid JSON']];
        }

        $company = trim((string)($decoded['company'] ?? ''));
        $licenseId = DeviceMetadata::normalizeLicenseId((string)($decoded['licenseId'] ?? ''));
        if ($company === '' || $licenseId === 0) {
            return ['error' => ['code' => 'invalid_request', 'message' => 'company and licenseId are required']];
        }

        if ($auth !== null && !$auth->isAdmin()) {
            if (!$auth->canAccessLicense($licenseId)) {
                return ['error' => ['code' => 'forbidden', 'message' => 'Forbidden']];
            }

            $currentLicenseId = DeviceMetadata::normalizeLicenseId((string)($existing['licenseId'] ?? '0'));
            $currentCompany = trim((string)($existing['company'] ?? 'null'));
            if ($currentLicenseId !== 0 || $currentCompany !== 'null') {
                return ['error' => ['code' => 'device_already_associated', 'message' => 'Device is already associated']];
            }
        }

        $license = $this->licenseForAssociation($company, $licenseId);
        if ($license === null) {
            return ['error' => ['code' => 'invalid_association', 'message' => 'company and licenseId do not match a registered license']];
        }

        $this->whitelist->updateAssociation($imei, $company, $licenseId);
        $this->store->updateDeviceAssociation($imei, $company, $licenseId);

        return [
            'status' => 'ok',
            'imei' => $imei,
            'association' => [
                'company' => $company,
                'licenseId' => $licenseId,
            ],
        ];
    }

    public function deleteAssociation(string $imei, ?ApiAuthContext $auth = null): array
    {
        $existing = $this->whitelist->getMetadata($imei);
        if ($existing === null) {
            return ['error' => ['code' => 'not_found', 'message' => 'Device was not found']];
        }

        $currentLicenseId = DeviceMetadata::normalizeLicenseId((string)($existing['licenseId'] ?? '0'));
        $currentCompany = trim((string)($existing['company'] ?? 'null'));
        if ($currentLicenseId === 0 && $currentCompany === 'null') {
            return ['error' => ['code' => 'association_not_found', 'message' => 'Device association was not found']];
        }

        if ($auth !== null && !$auth->isAdmin() && !$auth->canAccessLicense($currentLicenseId)) {
            return ['error' => ['code' => 'not_found', 'message' => 'Device was not found']];
        }

        $this->whitelist->updateAssociation($imei, 'null', 0);
        $this->store->updateDeviceAssociation($imei, 'null', 0);

        return [
            'status' => 'ok',
            'imei' => $imei,
            'association' => [
                'company' => 'null',
                'licenseId' => 0,
            ],
        ];
    }

    public function delete(string $imei): array
    {
        $this->whitelist->unregister($imei);
        $this->store->deleteDevice($imei);

        return ['status' => 'ok', 'imei' => $imei];
    }

    public function recent(string $imei, ?ApiAuthContext $auth = null): array
    {
        if (!$this->canAccessDevice($imei, $auth)) {
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

    private function pendingConfiguration(?array $model, string $protocol, array $configRows): array
    {
        $desiredCapabilities = $this->deviceCapabilitiesFromPayloadKey($model, $protocol, $configRows, 'desired_payload');
        $reportedCapabilities = $this->deviceCapabilitiesFromPayloadKey($model, $protocol, $configRows, 'reported_payload');
        $desiredValues = $this->flattenWritableCapabilities($desiredCapabilities);
        $reportedValues = $this->flattenWritableCapabilities($reportedCapabilities);
        $rowMeta = $this->genericCapabilityRowMeta($configRows);
        $pending = [];

        foreach ($desiredValues as $path => $desiredValue) {
            $reportedExists = array_key_exists($path, $reportedValues);
            $reportedValue = $reportedExists ? $reportedValues[$path] : null;
            if ($reportedExists && $this->capabilityValuesEqual($desiredValue, $reportedValue)) {
                continue;
            }

            [$section, $key] = explode('.', $path, 2);
            $meta = $rowMeta[$key] ?? [];
            $pending[$section][$key] = [
                'status' => $this->pendingStatus($meta['last_status'] ?? '', $reportedExists),
                'desired' => $desiredValue,
                'reported' => $reportedValue,
                'updatedAt' => $meta['updated_at'] ?? '',
                'lastCommandId' => $meta['last_command_id'] ?? '',
            ];
        }

        return $pending;
    }

    private function persistAndApplyConfiguration(
        string $imei,
        string $key,
        array $payload,
        string $supplierOverride = '',
        string $modelOverride = ''
    ): array {
        $device = $this->deviceSnapshot($imei);
        $metadata = $this->whitelist->getMetadata($imei) ?? [];
        $supplier = trim($supplierOverride) !== ''
            ? trim($supplierOverride)
            : (string)($device['supplier'] ?? ($metadata['supplier'] ?? ''));
        $model = trim($modelOverride) !== ''
            ? trim($modelOverride)
            : (string)($device['model'] ?? ($metadata['model'] ?? ''));
        $protocol = $this->protocolForModel($supplier, $model);
        if ($protocol === '') {
            $protocol = (string)($device['protocol'] ?? '');
        }
        if ($protocol === '') {
            return ['error' => ['code' => 'unknown_protocol', 'message' => 'Device protocol could not be resolved']];
        }

        $entry = DeviceConfigurationCatalog::configForProtocol($protocol, $key);
        if (($entry['transient'] ?? false) === true) {
            return ['error' => ['code' => 'invalid_config', 'message' => "{$key} is a transient action and must be requested via /requests"]];
        }

        $error = DeviceConfigurationCatalog::validate($protocol, $key, $payload);
        if ($error !== null) {
            return ['error' => ['code' => 'invalid_config', 'message' => $error]];
        }

        $commandPayload = DeviceConfigurationCatalog::commandPayload($protocol, $key, $payload);
        $command = $commandPayload['command'];
        $bytes = DeviceCommandCatalog::buildDownlink($protocol, $imei, $command, $commandPayload['payload'], [
            'deviceId' => (string)($metadata['deviceId'] ?? $device['deviceId'] ?? ''),
        ]);
        $id = bin2hex(random_bytes(8));
        $status = $this->hub->submitDownlink($imei, $bytes);
        $record = [
            'status' => $status === 'sent' ? 'waiting' : $status,
            'imei' => $imei,
            'protocol' => $protocol,
            'nativeType' => $command,
            'label' => (string)(DeviceConfigurationCatalog::configForProtocol($protocol, $key)['label'] ?? $key),
            'configKey' => $key,
            'expectedReplyTypes' => DeviceConfigurationCatalog::configForProtocol($protocol, $key)['expectedReplyTypes'] ?? [],
            'requestedAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
        ];
        if ($status === 'sent') {
            $record['sentAt'] = gmdate('Y-m-d\\TH:i:s\\Z');
        }
        if ($status === 'dropped') {
            $record['error'] = 'delivery_failed';
        }
        $this->store->recordCommand($imei, $id, $record);
        $this->db->deviceConfigurations->saveDesired($imei, $key, $protocol, $supplier, $model, $command, $payload, (string)$record['status'], $id);

        return ['status' => $record['status'], 'key' => $key, 'command' => $command, 'id' => $id];
    }

    private function saveGenericConfiguration(string $imei, array $decoded, string $requestId = ''): array
    {
        if (!is_array($decoded['capabilities'] ?? null)) {
            Logger::channel('api')->warning('API device configuration rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'error_code' => 'invalid_request',
                'reason' => 'missing_capabilities_object',
            ]);
            return ['error' => ['code' => 'invalid_request', 'message' => 'capabilities object is required']];
        }

        $device = $this->deviceSnapshot($imei);
        $metadata = $this->whitelist->getMetadata($imei) ?? [];
        $supplier = trim((string)($decoded['supplier'] ?? '')) !== ''
            ? trim((string)$decoded['supplier'])
            : (string)($device['supplier'] ?? ($metadata['supplier'] ?? ''));
        $modelName = trim((string)($decoded['model'] ?? '')) !== ''
            ? trim((string)$decoded['model'])
            : (string)($device['model'] ?? ($metadata['model'] ?? ''));
        $protocol = $this->protocolForModel($supplier, $modelName);
        if ($protocol === '') {
            $protocol = (string)($device['protocol'] ?? '');
        }
        if ($protocol === '') {
            Logger::channel('api')->warning('API device configuration rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'error_code' => 'unknown_protocol',
            ]);
            return ['error' => ['code' => 'unknown_protocol', 'message' => 'Device protocol could not be resolved']];
        }

        $modelRow = $this->modelForSupplierAndName($supplier, $modelName);
        $enabled = array_flip($modelRow !== null
            ? $this->db->modelCapabilities->enabledFeaturesForModelId((int)($modelRow['id'] ?? 0))
            : GenericModelCapabilityCatalog::keysForProtocol($protocol));
        $configRows = $this->db->deviceConfigurations->allForImei($imei);
        $currentValues = $this->flattenWritableCapabilities($this->deviceCapabilities($modelRow, $protocol, $configRows));
        $currentRowsByKey = [];
        foreach ($configRows as $row) {
            $currentRowsByKey[(string)($row['config_key'] ?? '')] = $row;
        }

        try {
            $requested = $this->parseWritableCapabilitiesInput($decoded['capabilities'], $enabled);
        } catch (\InvalidArgumentException $e) {
            Logger::channel('api')->warning('API device configuration rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'error_code' => 'invalid_config',
                'message' => $e->getMessage(),
            ]);
            return ['error' => ['code' => 'invalid_config', 'message' => $e->getMessage()]];
        }

        $changed = [];
        $unchanged = [];
        foreach ($requested as $path => $value) {
            $currentValue = $currentValues[$path] ?? null;
            if (array_key_exists($path, $currentValues) && $this->capabilityValuesEqual($currentValue, $value)) {
                $unchanged[] = $path;
                continue;
            }

            [, $genericKey] = explode('.', $path, 2);
            try {
                $nativeUpdates = $this->genericCapabilityToNativeUpdates($protocol, $genericKey, $value);
            } catch (\InvalidArgumentException $e) {
                Logger::channel('api')->warning('API device configuration rejected', [
                    'request_id' => $requestId,
                    'imei' => $imei,
                    'capability_path' => $path,
                    'error_code' => 'invalid_config',
                    'message' => $e->getMessage(),
                ]);
                return ['error' => ['code' => 'invalid_config', 'message' => $e->getMessage()]];
            }

            $pathResults = [];
            foreach ($nativeUpdates as $nativeKey => $payload) {
                $existingPayload = is_array($currentRowsByKey[$nativeKey]['desired_payload'] ?? null)
                    ? $currentRowsByKey[$nativeKey]['desired_payload']
                    : null;
                if ($existingPayload !== null && $this->capabilityValuesEqual($existingPayload, $payload)) {
                    continue;
                }

                $result = $this->persistAndApplyConfiguration($imei, $nativeKey, $payload, $supplier, $modelName);
                if (isset($result['error'])) {
                    Logger::channel('api')->warning('API device configuration rejected', [
                        'request_id' => $requestId,
                        'imei' => $imei,
                        'capability_path' => $path,
                        'config_key' => $nativeKey,
                        'error_code' => $result['error']['code'] ?? 'invalid_config',
                    ]);
                    return $result;
                }
                $pathResults[] = [
                    'key' => $nativeKey,
                    'command' => $result['command'],
                    'deliveryStatus' => $result['status'],
                    'lastCommandId' => $result['id'],
                ];
            }

            if ($pathResults === []) {
                $unchanged[] = $path;
                continue;
            }

            $changed[$path] = count($pathResults) === 1
                ? $pathResults[0]
                : ['operations' => $pathResults];
        }

        $snapshot = $this->show($imei);

        Logger::channel('api')->info('API device configuration processed', [
            'request_id' => $requestId,
            'imei' => $imei,
            'mode' => 'capabilities',
            'changed_paths' => array_keys($changed),
            'unchanged_paths' => array_values($unchanged),
        ]);

        return [
            'status' => 'ok',
            'changed' => $changed,
            'unchanged' => $unchanged,
            'configuration' => $snapshot['configuration'] ?? [],
            'capabilities' => $snapshot['capabilities'] ?? [],
            'pending' => $snapshot['pending'] ?? [],
            'transportPending' => $snapshot['transportPending'] ?? [],
        ];
    }

    private function protocolForModel(string $supplier, string $model): string
    {
        return DeviceProtocol::forSupplier($supplier);
    }

    private function normalizeDeviceId(string $imei, string $supplier, string $model, string $deviceId): string
    {
        if ($this->protocolForModel($supplier, $model) !== 'four-p-touch') {
            return $deviceId;
        }

        $derived = DeviceCommandCatalog::deriveFourPTouchDeviceId($imei);
        return $derived !== '' ? $derived : $deviceId;
    }

    private function modelForDevice(array $device): ?array
    {
        return $this->modelForSupplierAndName((string)($device['supplier'] ?? ''), (string)($device['model'] ?? ''));
    }

    private function modelForSupplierAndName(string $supplier, string $model): ?array
    {
        if (trim($supplier) === '' || trim($model) === '') {
            return null;
        }

        return $this->db->models->find($supplier, $model);
    }

    private function baseUrlFromRequest(?ServerRequestInterface $request): string
    {
        if ($request === null) {
            return 'http://localhost:8081';
        }

        $uri = $request->getUri();
        $scheme = $uri->getScheme() !== '' ? $uri->getScheme() : 'http';
        $host = $uri->getHost() !== '' ? $uri->getHost() : 'localhost';
        $port = $uri->getPort();
        $base = $scheme . '://' . $host;
        if ($port !== null && $port !== 80 && $port !== 443) {
            $base .= ':' . $port;
        }

        return $base;
    }

    private function fullModelImage(string $path, string $baseUrl): ?string
    {
        return $path !== '' ? $baseUrl . $path : null;
    }

    private function attachDeviceImage(array $device, string $baseUrl): array
    {
        $modelRow = $this->modelForSupplierAndName(
            (string)($device['supplier'] ?? ''),
            (string)($device['model'] ?? '')
        );
        $device['image'] = $modelRow !== null
            ? $this->fullModelImage((string)($modelRow['image_path'] ?? ''), $baseUrl)
            : null;

        return $device;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function enabledRequestCommandsForModel(?array $model, string $protocol): array
    {
        $commands = array_values(array_filter(
            DeviceCommandCatalog::commandsForProtocol($protocol),
            static fn(array $entry): bool => (string)($entry['kind'] ?? '') === 'request'
        ));

        if ($model === null) {
            return [];
        }

        $enabled = array_flip($this->db->modelCapabilities->enabledFeaturesForModelId((int)$model['id']));

        return array_values(array_filter($commands, static function (array $entry) use ($enabled): bool {
            $feature = (string)($entry['feature'] ?? '');
            return $feature !== '' && isset($enabled[$feature]);
        }));
    }

    private function filterDevices(array $devices, array $filters): array
    {
        return array_values(array_filter($devices, function (array $device) use ($filters): bool {
            $deviceType = DeviceMetadata::normalizeDeviceType((string)($device['deviceType'] ?? 'watch'));
            $licenseId = DeviceMetadata::normalizeLicenseId((string)($device['licenseId'] ?? '0'));
            $company = trim((string)($device['company'] ?? 'null'));
            $supplier = trim((string)($device['supplier'] ?? ''));
            $model = trim((string)($device['model'] ?? ''));
            $modelFilter = trim((string)($filters['model'] ?? ''));
            $query = trim((string)($filters['q'] ?? ''));

            return (($filters['deviceType'] ?? null) === null || $deviceType === $filters['deviceType'])
                && (($filters['licenseId'] ?? null) === null || $licenseId === $filters['licenseId'])
                && (($filters['company'] ?? null) === null || $company === $filters['company'])
                && (($filters['supplier'] ?? null) === null || $supplier === $filters['supplier'])
                && ($modelFilter === '' || str_contains($this->normalizeSearchText($model), $this->normalizeSearchText($modelFilter)))
                && ($query === '' || $this->matchesDeviceQuery($device, $query));
        }));
    }

    private function matchesDeviceQuery(array $device, string $query): bool
    {
        $normalizedQuery = $this->normalizeSearchText($query);
        $tokens = array_values(array_filter(preg_split('/\s+/u', $normalizedQuery) ?: [], static fn (string $token): bool => $token !== ''));
        if ($tokens === []) {
            return true;
        }

        $haystack = $this->normalizeSearchText(implode(' ', [
            (string)($device['imei'] ?? ''),
            (string)($device['supplier'] ?? ''),
            (string)($device['model'] ?? ''),
            (string)($device['simNumber'] ?? ''),
            (string)($device['company'] ?? ''),
        ]));

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (str_contains($haystack, $token)) {
                continue;
            }

            return false;
        }

        return true;
    }

    private function normalizeSearchText(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $lower = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $lower) ?? $lower;
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized)) ?? trim($normalized);

        return $normalized;
    }

    private function filterDevicesForOptions(array $devices, array $filters, string $excludeKey): array
    {
        $candidateFilters = $filters;
        $candidateFilters[$excludeKey] = null;

        return $this->filterDevices($devices, $candidateFilters);
    }

    private function canAccessDevice(string $imei, ?ApiAuthContext $auth, ?array $device = null): bool
    {
        if ($auth === null || $auth->isAdmin()) {
            return true;
        }

        $device ??= $this->deviceSnapshot($imei);
        $licenseId = $this->deviceLicenseId($imei, $device);

        return $auth->canAccessLicense($licenseId);
    }

    private function deviceLicenseId(string $imei, array $device): int
    {
        $licenseId = trim((string)($device['licenseId'] ?? ''));
        if ($licenseId !== '') {
            return DeviceMetadata::normalizeLicenseId($licenseId);
        }

        $metadata = $this->whitelist->getMetadata($imei) ?? [];
        return DeviceMetadata::normalizeLicenseId((string)($metadata['licenseId'] ?? '0'));
    }

    private function scopeDevices(array $devices, ?ApiAuthContext $auth): array
    {
        if ($auth === null || $auth->isAdmin()) {
            return $devices;
        }

        return array_values(array_filter(
            $devices,
            fn(array $device): bool => $this->canAccessDevice((string)($device['imei'] ?? ''), $auth, $device)
        ));
    }

    private function normalizeLicenseId(string $licenseId, string $deviceType): int
    {
        $normalized = trim($licenseId);

        if ($normalized === '' && $deviceType === 'watch') {
            return 0;
        }

        return $normalized !== '' ? (int)$normalized : 0;
    }

    /**
     * @param list<array<string, mixed>> $configRows
     * @return array<string, array<string, mixed>>
     */
    private function deviceCapabilities(?array $model, string $protocol, array $configRows): array
    {
        return $this->deviceCapabilitiesFromPayloadKey($model, $protocol, $configRows, 'desired_payload');
    }

    /**
     * @param list<array<string, mixed>> $configRows
     * @return array<string, array<string, mixed>>
     */
    private function deviceCapabilitiesFromPayloadKey(?array $model, string $protocol, array $configRows, string $payloadKey): array
    {
        $deviceType = DeviceMetadata::normalizeDeviceType((string)($model['device_type'] ?? 'watch'));
        $catalog = $this->db->genericCapabilities->all($deviceType);
        $supportedKeys = $model !== null
            ? $this->db->modelCapabilities->enabledFeaturesForModelId((int)($model['id'] ?? 0))
            : GenericModelCapabilityCatalog::keysForProtocol($protocol);
        $matrix = GenericModelCapabilityCatalog::buildCapabilityMatrix($catalog, $supportedKeys);
        $capabilities = [];

        foreach (GenericModelCapabilityCatalog::sections() as $section => $_label) {
            $capabilities[$section] = [];
        }

        $capabilities['telemetry'] = $this->telemetryCapabilities($model, $protocol, $matrix);

        $meta = [];
        $nativeKeysPerGeneric = [];
        $nativeKeyForGeneric = [];

        foreach ($configRows as $row) {
            $nativeKey = trim((string)($row['config_key'] ?? ''));
            $payload = is_array($row[$payloadKey] ?? null) ? $row[$payloadKey] : [];
            if ($nativeKey === '' || $payload === []) {
                continue;
            }

            $genericKey = GenericModelCapabilityCatalog::mapConfigurationKey($nativeKey);
            if ($genericKey === null) {
                continue;
            }

            $section = GenericModelCapabilityCatalog::sectionForCapabilityKey($genericKey);
            if ($section === null || $section === 'telemetry') {
                continue;
            }

            $normalized = $this->normalizeCapabilityValue($genericKey, $nativeKey, $payload);
            if ($normalized === null) {
                continue;
            }

            $capabilities[$section][$genericKey] = $this->mergeCapabilityValue(
                $genericKey,
                $capabilities[$section][$genericKey] ?? null,
                $normalized
            );

            $nativeKeyForGeneric[$genericKey] = $nativeKey;
            $nativeKeysPerGeneric[$genericKey][$nativeKey] = true;

            $entry = DeviceConfigurationCatalog::configForProtocol($protocol, $nativeKey);
            if ($entry === null) {
                continue;
            }

            if (isset($entry['options'])) {
                foreach ($entry['options'] as $field => $options) {
                    $meta[$genericKey][$field] = ['options' => $options];
                }
            }

            if (isset($entry['limit'])) {
                $existing = $meta[$genericKey]['limit'] ?? 0;
                $meta[$genericKey]['limit'] = max($existing, (int)$entry['limit']);
            }
        }

        foreach ($meta as $genericKey => $metaData) {
            if (count($nativeKeysPerGeneric[$genericKey] ?? []) > 1) {
                continue;
            }

            foreach ($capabilities as $section => &$sectionCaps) {
                if (array_key_exists($genericKey, $sectionCaps)) {
                    $sectionCaps[$genericKey] = [
                        'value' => $sectionCaps[$genericKey],
                        '_meta' => $metaData,
                    ];
                    break;
                }
            }
            unset($sectionCaps);
        }

        foreach ($capabilities as $section => &$sectionCaps) {
            if ($section === 'telemetry') {
                continue;
            }
            foreach ($sectionCaps as $genericKey => &$value) {
                if (isset($nativeKeyForGeneric[$genericKey])) {
                    $value['_nativeKey'] = $nativeKeyForGeneric[$genericKey];
                }
            }
            unset($value);
        }
        unset($sectionCaps);

        return $capabilities;
    }

    /**
     * @param array<string, mixed>|null $matrix
     * @return array<string, array{supported: bool, requestable: bool}>
     */
    private function telemetryCapabilities(?array $model, string $protocol, ?array $matrix = null): array
    {
        if ($matrix === null) {
            $deviceType = DeviceMetadata::normalizeDeviceType((string)($model['device_type'] ?? 'watch'));
            $catalog = $this->db->genericCapabilities->all($deviceType);
            $supportedKeys = $model !== null
                ? $this->db->modelCapabilities->enabledFeaturesForModelId((int)($model['id'] ?? 0))
                : GenericModelCapabilityCatalog::keysForProtocol($protocol);
            $matrix = GenericModelCapabilityCatalog::buildCapabilityMatrix($catalog, $supportedKeys);
        }

        $requestable = [];
        foreach ($this->enabledRequestCommandsForModel($model, $protocol) as $entry) {
            $feature = trim((string)($entry['feature'] ?? ''));
            if ($feature !== '') {
                $requestable[$feature] = true;
            }
        }

        $telemetry = [];
        foreach ($matrix['telemetry'] ?? [] as $key => $supported) {
            if (!$supported) {
                continue;
            }
            $telemetry[$key] = [
                'supported' => true,
                'requestable' => isset($requestable[$key]),
            ];
        }

        return $telemetry;
    }


    /**
     * @param array<string, mixed> $capabilities
     * @return array<string, mixed>
     */
    private function flattenWritableCapabilities(array $capabilities): array
    {
        $flattened = [];
        foreach ($capabilities as $section => $entries) {
            if ($section === 'telemetry' || !is_array($entries)) {
                continue;
            }
            foreach ($entries as $key => $value) {
                $flattened["{$section}.{$key}"] = $this->extractCapabilityValue($value);
            }
        }

        return $flattened;
    }

    private function extractCapabilityValue(mixed $value): mixed
    {
        if (is_array($value) && array_key_exists('value', $value) && count(array_diff(array_keys($value), ['value', '_meta'])) === 0) {
            return $value['value'];
        }

        return $value;
    }

    private function capabilityValuesEqual(mixed $left, mixed $right): bool
    {
        return $this->normalizeComparableValue($left) === $this->normalizeComparableValue($right);
    }

    private function normalizeComparableValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->normalizeComparableValue($item), $value);
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[(string)$key] = $this->normalizeComparableValue($item);
        }
        ksort($normalized);

        return $normalized;
    }

    /**
     * @param array<string, bool> $enabled
     * @return array<string, mixed>
     */
    private function parseWritableCapabilitiesInput(array $capabilities, array $enabled): array
    {
        $requested = [];
        foreach ($capabilities as $section => $entries) {
            if (!is_string($section) || !is_array($entries)) {
                throw new \InvalidArgumentException('Each capabilities section must be an object');
            }
            if ($section === 'telemetry') {
                throw new \InvalidArgumentException('Telemetry capabilities are read-only');
            }
            if (!array_key_exists($section, GenericModelCapabilityCatalog::sections())) {
                throw new \InvalidArgumentException("Unknown capability section {$section}");
            }

            foreach ($entries as $key => $rawValue) {
                if (!is_string($key)) {
                    throw new \InvalidArgumentException('Capability keys must be strings');
                }
                $expectedSection = GenericModelCapabilityCatalog::sectionForCapabilityKey($key);
                if ($expectedSection === null || $expectedSection !== $section) {
                    throw new \InvalidArgumentException("Unsupported capability {$section}.{$key}");
                }
                if (!isset($enabled[$key])) {
                    throw new \InvalidArgumentException("Capability {$key} is not enabled for this model");
                }

                $value = $this->sanitizeWritableCapabilityValue($rawValue);
                $requested["{$section}.{$key}"] = $value;
            }
        }

        return $requested;
    }

    private function sanitizeWritableCapabilityValue(mixed $rawValue): mixed
    {
        if (is_array($rawValue) && array_key_exists('value', $rawValue)) {
            return $rawValue['value'];
        }
        if (is_array($rawValue) && array_key_exists('_meta', $rawValue) && !array_key_exists('value', $rawValue)) {
            throw new \InvalidArgumentException('Capability value is required');
        }

        return $rawValue;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function genericCapabilityToNativeUpdates(string $protocol, string $genericKey, mixed $value): array
    {
        return match ($protocol) {
            'vivistar-iw' => $this->vivistarGenericCapabilityUpdates($genericKey, $value),
            'wonlex-json' => $this->wonlexGenericCapabilityUpdates($genericKey, $value),
            'four-p-touch' => $this->fourPTouchGenericCapabilityUpdates($genericKey, $value),
            default => throw new \InvalidArgumentException("Unsupported protocol {$protocol}"),
        };
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function vivistarGenericCapabilityUpdates(string $genericKey, mixed $value): array
    {
        return match ($genericKey) {
            'sos_contacts' => ['sosContacts' => ['numbers' => $this->requireStringListValue($value, 'numbers')]],
            'phonebook' => ['phonebook' => ['contacts' => $this->requireListValue($value, 'contacts')]],
            'working_mode' => ['workingMode' => $this->requireObjectValue($value, 'workingMode')],
            'fall_detection' => ['fallDetection' => ['enabled' => $this->requireBoolLikeField($value, 'enabled')]],
            'fall_sensitivity' => ['fallSensitivity' => ['sensitivity' => $this->requireIntField($value, 'sensitivity')]],
            'call_whitelist' => ['whitelistSwitch' => ['enabled' => $this->requireBoolLikeField($value, 'enabled')]],
            'push_message' => ['pushMessage' => ['message' => $this->requireStringField($value, 'message')]],
            'alarm_clock' => ['reminders' => $this->normalizeReminderCapabilityValue($value)],
            'auto_vitals_interval' => ['autoHealthMeasurement' => $this->requireObjectValue($value, 'autoHealthMeasurement')],
            'blood_pressure_calibration' => ['bloodPressureCalibration' => $this->requireObjectValue($value, 'bloodPressureCalibration')],
            default => throw new \InvalidArgumentException("Unsupported vivistar-iw capability {$genericKey}"),
        };
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function wonlexGenericCapabilityUpdates(string $genericKey, mixed $value): array
    {
        return match ($genericKey) {
            'sos_contacts' => ['SOSNumber' => ['numbers' => $this->requireStringListValue($value, 'numbers')]],
            'alarm_clock' => ['alarmClock' => ['alarmClock' => $this->requireListValue($value, 'alarmClock')]],
            'medication_reminders' => ['dnMedicationPlan' => ['plans' => $this->requireListValue($value, 'plans')]],
            default => [$this->resolveWonlexNativeKey($genericKey) => $this->requireObjectValue($value, $genericKey)],
        };
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fourPTouchGenericCapabilityUpdates(string $genericKey, mixed $value): array
    {
        return match ($genericKey) {
            'location_reporting_interval' => ['uploadInterval' => $this->requireObjectValue($value, 'uploadInterval')],
            'sos_contacts' => $this->fourPTouchSosUpdates($value),
            'call_whitelist' => $this->fourPTouchWhitelistUpdates($value),
            'monitor_number' => ['monitorNumber' => ['phone' => $this->requireStringValue($value, 'phone')]],
            'device_password' => ['devicePassword' => $this->requireObjectValue($value, 'devicePassword')],
            'language_timezone' => ['languageTimezone' => $this->requireObjectValue($value, 'languageTimezone')],
            'sos_sms_alert' => ['sosSmsAlerts' => ['enabled' => $this->requireBoolLikeValue($value, 'enabled')]],
            'low_battery_alert' => ['lowBatterySmsAlerts' => ['enabled' => $this->requireBoolLikeValue($value, 'enabled')]],
            'remove_watch_alarm' => ['removeWatchAlarm' => ['enabled' => $this->requireBoolLikeValue($value, 'enabled')]],
            'remove_watch_sms_alert' => ['removeWatchSmsAlerts' => ['enabled' => $this->requireBoolLikeValue($value, 'enabled')]],
            'fall_detection' => ['fallDownAlert' => $this->requireObjectValue($value, 'fallDownAlert')],
            'fall_sensitivity' => ['fallDownSensitivity' => $this->requireObjectValue($value, 'fallDownSensitivity')],
            'auto_vitals_interval' => ['healthAutoMeasurement' => $this->requireObjectValue($value, 'healthAutoMeasurement')],
            'pedometer_schedule' => ['walkTime' => ['ranges' => $this->requireListValue(is_array($value) && array_key_exists('ranges', $value) ? $value['ranges'] : $value, 'ranges')]],
            'sleep_monitoring' => ['sleepTime' => ['range' => $this->requireStringField($value, 'range')]],
            'temperature_measurement_interval' => ['bodyTemperatureInterval' => $this->requireObjectValue($value, 'bodyTemperatureInterval')],
            default => throw new \InvalidArgumentException("Unsupported four-p-touch capability {$genericKey}"),
        };
    }

    private function resolveWonlexNativeKey(string $genericKey): string
    {
        foreach (DeviceConfigurationCatalog::configsForProtocol('wonlex-json') as $entry) {
            $nativeKey = trim((string)($entry['key'] ?? ''));
            if (GenericModelCapabilityCatalog::mapConfigurationKey($nativeKey) === $genericKey) {
                return $nativeKey;
            }
        }

        throw new \InvalidArgumentException("Unsupported wonlex-json capability {$genericKey}");
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fourPTouchSosUpdates(mixed $value): array
    {
        $numbers = $this->requireStringListValue($value, 'numbers');
        $updates = [];
        foreach (array_slice($numbers, 0, 3) as $index => $phone) {
            if (trim($phone) === '') {
                continue;
            }
            $updates['sosNumber' . ($index + 1)] = ['phone' => $phone];
        }

        return $updates;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fourPTouchWhitelistUpdates(mixed $value): array
    {
        $numbers = [];
        if (is_array($value) && array_key_exists('numbers', $value)) {
            $numbers = $this->requireStringListValue($value['numbers'], 'numbers');
        } else {
            throw new \InvalidArgumentException('numbers must be an array');
        }

        return [
            'whitelistGroup1' => ['numbers' => array_slice($numbers, 0, 5)],
            'whitelistGroup2' => ['numbers' => array_slice($numbers, 5, 5)],
        ];
    }

    private function normalizeReminderCapabilityValue(mixed $value): array
    {
        if (is_array($value) && array_is_list($value)) {
            return [
                'masterEnabled' => true,
                'items' => $value,
            ];
        }

        $payload = $this->requireObjectValue($value, 'reminders');
        $payload['items'] = $this->requireListValue($payload['items'] ?? [], 'items');
        $payload['masterEnabled'] = $payload['masterEnabled'] ?? true;

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function requireStringListValue(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException("{$field} must be an array");
        }

        return $this->stringList($value);
    }

    /**
     * @return list<mixed>
     */
    private function requireListValue(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException("{$field} must be an array");
        }

        return array_values($value);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireObjectValue(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException("{$field} must be an object");
        }

        return $value;
    }

    private function requireStringValue(mixed $value, string $field): string
    {
        if (is_array($value)) {
            if (!array_key_exists($field, $value)) {
                throw new \InvalidArgumentException("{$field} is required");
            }
            $value = $value[$field];
        }

        $string = trim((string)$value);
        if ($string === '') {
            throw new \InvalidArgumentException("{$field} is required");
        }

        return $string;
    }

    private function requireStringField(mixed $value, string $field): string
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException("{$field} is required");
        }

        return $this->requireStringValue($value[$field] ?? null, $field);
    }

    private function requireIntField(mixed $value, string $field): int
    {
        if (!is_array($value) || !is_numeric((string)($value[$field] ?? null))) {
            throw new \InvalidArgumentException("{$field} must be an integer");
        }

        return (int)$value[$field];
    }

    private function requireBoolLikeField(mixed $value, string $field): bool|int|string
    {
        if (!is_array($value) || !array_key_exists($field, $value)) {
            throw new \InvalidArgumentException("{$field} is required");
        }

        return $value[$field];
    }

    private function requireBoolLikeValue(mixed $value, string $field): bool|int|string
    {
        if (is_array($value)) {
            return $this->requireBoolLikeField($value, $field);
        }
        if (is_bool($value) || $value === 0 || $value === 1 || $value === '0' || $value === '1') {
            return $value;
        }

        throw new \InvalidArgumentException("{$field} must be boolean or 0/1");
    }

    /**
     * @param list<array<string, mixed>> $configRows
     * @return array<string, array{updated_at: string, last_status: string, last_command_id: string}>
     */
    private function genericCapabilityRowMeta(array $configRows): array
    {
        $meta = [];
        foreach ($configRows as $row) {
            $nativeKey = trim((string)($row['config_key'] ?? ''));
            $genericKey = GenericModelCapabilityCatalog::mapConfigurationKey($nativeKey);
            if ($genericKey === null) {
                continue;
            }
            $updatedAt = (string)($row['desired_updated_at'] ?? '');
            if (!isset($meta[$genericKey]) || strcmp($updatedAt, $meta[$genericKey]['updated_at']) >= 0) {
                $meta[$genericKey] = [
                    'updated_at' => $updatedAt,
                    'last_status' => (string)($row['last_status'] ?? ''),
                    'last_command_id' => (string)($row['last_command_id'] ?? ''),
                ];
            }
        }

        return $meta;
    }

    private function pendingStatus(string $lastStatus, bool $reportedExists): string
    {
        if (!$reportedExists) {
            return in_array($lastStatus, ['queued', 'waiting', 'sent', 'acked'], true) ? 'waiting_device' : 'never_reported';
        }

        return 'diverged';
    }

    private function normalizeCapabilityValue(string $genericKey, string $nativeKey, array $desired): mixed
    {
        return match ($genericKey) {
            'sos_contacts' => $this->normalizeSosContactsValue($nativeKey, $desired),
            'phonebook' => $desired['contacts'] ?? null,
            'call_whitelist' => $this->normalizeCallWhitelistValue($nativeKey, $desired),
            'monitor_number' => $desired['phone'] ?? $desired,
            'alarm_clock' => $desired['alarmClock'] ?? $desired['items'] ?? $desired,
            'medication_reminders' => $desired['plans'] ?? $desired['items'] ?? $desired,
            default => $desired,
        };
    }

    private function mergeCapabilityValue(string $genericKey, mixed $existing, mixed $incoming): mixed
    {
        if ($existing === null) {
            return $incoming;
        }

        return match ($genericKey) {
            'sos_contacts' => $this->mergeStringLists($existing, $incoming),
            'call_whitelist' => $this->mergeAssociativeValues($existing, $incoming, ['numbers']),
            'phonebook', 'alarm_clock', 'medication_reminders' => $this->mergeListValues($existing, $incoming),
            default => $this->mergeAssociativeValues($existing, $incoming),
        };
    }

    private function normalizeSosContactsValue(string $nativeKey, array $desired): array
    {
        if (isset($desired['numbers']) && is_array($desired['numbers'])) {
            return $this->stringList($desired['numbers']);
        }
        if (isset($desired['phone'])) {
            return $this->stringList([$desired['phone']]);
        }
        if ($nativeKey === 'SOSNumber' && isset($desired['SOSNumber']) && is_array($desired['SOSNumber'])) {
            return $this->stringList($desired['SOSNumber']);
        }

        return [];
    }

    private function normalizeCallWhitelistValue(string $nativeKey, array $desired): array
    {
        if ($nativeKey === 'whitelistSwitch') {
            return ['enabled' => (bool)($desired['enabled'] ?? false)];
        }

        return ['numbers' => $this->stringList($desired['numbers'] ?? [])];
    }

    private function mergeStringLists(mixed $existing, mixed $incoming): array
    {
        return $this->stringList(array_merge(
            is_array($existing) ? $existing : [],
            is_array($incoming) ? $incoming : []
        ));
    }

    private function mergeListValues(mixed $existing, mixed $incoming): array
    {
        $existingList = is_array($existing) ? array_values($existing) : [];
        $incomingList = is_array($incoming) ? array_values($incoming) : [];

        return array_values(array_merge($existingList, $incomingList));
    }

    /**
     * @param list<string> $listKeys
     */
    private function mergeAssociativeValues(mixed $existing, mixed $incoming, array $listKeys = []): mixed
    {
        if (!is_array($existing) || !is_array($incoming)) {
            return $incoming;
        }

        $merged = $existing;
        foreach ($incoming as $key => $value) {
            if (in_array((string)$key, $listKeys, true)) {
                $merged[$key] = $this->stringList(array_merge(
                    is_array($existing[$key] ?? null) ? $existing[$key] : [],
                    is_array($value) ? $value : []
                ));
                continue;
            }

            $merged[$key] = $value;
        }

        return $merged;
    }

    /**
     * @param list<mixed> $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $item = trim((string)$value);
            if ($item === '') {
                continue;
            }
            $normalized[$item] = true;
        }

        return array_keys($normalized);
    }

    private function licenseForAssociation(string $company, string $licenseId): ?array
    {
        $companyRow = $this->db->companies->findByName($company);
        if ($companyRow === null) {
            return null;
        }

        foreach ($this->db->licenses->findByLicenseId($licenseId) as $row) {
            if ((int)($row['company_id'] ?? 0) === (int)($companyRow['id'] ?? 0)) {
                return $row;
            }
        }

        return null;
    }

    private function deviceSnapshot(string $imei): array
    {
        $device = $this->db->whitelist->getDevice($imei) ?? ['imei' => $imei];
        $storeDevice = $this->store->device($imei);
        $metadata = $this->whitelist->getMetadata($imei) ?? [];
        $device = array_merge(
            $device,
            array_filter($storeDevice, static fn (mixed $value): bool => $value !== '' && $value !== null)
        );
        $device += [
            'supplier' => (string)($metadata['supplier'] ?? ''),
            'model' => (string)($metadata['model'] ?? ''),
            'deviceType' => (string)($metadata['deviceType'] ?? 'watch'),
            'licenseId' => (int)($metadata['licenseId'] ?? 0),
            'simNumber' => (string)($metadata['simNumber'] ?? ''),
            'deviceId' => (string)($metadata['deviceId'] ?? ''),
            'company' => (string)($metadata['company'] ?? 'null'),
        ];
        $runtimeStates = $this->store->runtimeStates([$imei]);

        return $this->overlayRuntimeState($device, $runtimeStates);
    }

    /**
     * @param array<string, array<string, mixed>> $runtimeStates
     */
    private function overlayRuntimeState(array $device, array $runtimeStates): array
    {
        $imei = (string)($device['imei'] ?? '');
        if ($imei === '' || !isset($runtimeStates[$imei])) {
            $device['online'] = (bool)($device['online'] ?? false);
            return $device;
        }

        $runtime = $runtimeStates[$imei];
        foreach ([
            'online',
            'lastSeenAt',
            'lastStateAt',
            'protocol',
            'transport',
            'lastConnectionId',
        ] as $field) {
            if (array_key_exists($field, $runtime)) {
                $device[$field] = $runtime[$field];
            }
        }

        return $device;
    }
}
