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
use Hub\PendingDownlinkQueue;
use Hub\Registry\Whitelist;

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

    public function list(string $query = '', ?ApiAuthContext $auth = null): array
    {
        $params = $this->queryParams($query);
        $page = $this->queryPage($params);
        $limit = $this->queryLimit($params, 5);
        $filters = [
            'deviceType' => $this->queryFilter($params, 'deviceType'),
            'licenseId' => $this->queryFilter($params, 'licenseId'),
            'supplier' => $this->queryFilter($params, 'supplier'),
            'model' => $this->queryFilter($params, 'model'),
            'q' => $this->queryFilter($params, 'q', ''),
        ];
        $licenseScope = $auth !== null && !$auth->isAdmin() ? $auth->licenseId : null;
        $result = $this->db->whitelist->listPage($filters, $page, $limit, $licenseScope);
        if ((int)$result['total'] === 0) {
            $devices = $this->scopeDevices($this->store->devices(), $auth);
            $filtered = $this->filterDevices($devices, $filters);
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
        $items = array_map(fn (array $device): array => $this->overlayRuntimeState($device, $runtimeStates), $result['items']);
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

    public function show(string $imei, ?ApiAuthContext $auth = null): array
    {
        $device = $this->deviceSnapshot($imei);
        if (!$this->canAccessDevice($imei, $auth, $device)) {
            return ['error' => ['code' => 'not_found', 'message' => 'Device was not found']];
        }
        $protocol = (string)($device['protocol'] ?? $this->protocolForModel((string)($device['supplier'] ?? ''), (string)($device['model'] ?? '')));
        $model = $this->modelForDevice($device);
        $configRows = $this->db->deviceConfigurations->allForImei($imei);

        return [
            'device' => $device,
            'configuration' => [
                'supported' => count(DeviceConfigurationCatalog::configsForProtocol($protocol)),
                'stored' => count($configRows),
            ],
            'capabilities' => $this->deviceCapabilities($model, $protocol, $configRows),
            'pending' => $this->pending($imei),
        ];
    }

    public function command(string $imei, string $body, ?ApiAuthContext $auth = null): array
    {
        if (!$this->canAccessDevice($imei, $auth)) {
            return ['error' => ['code' => 'not_found', 'message' => 'Device was not found']];
        }

        $decoded = json_decode($body, true);
        $requestId = is_array($decoded)
            ? trim((string)($decoded['requestId'] ?? $decoded['command'] ?? ''))
            : '';
        $feature = is_array($decoded) ? trim((string)($decoded['feature'] ?? '')) : '';

        if ($requestId === '' && $feature === '') {
            return ['error' => ['code' => 'invalid_request', 'message' => 'requestId or feature is required']];
        }

        $device = $this->deviceSnapshot($imei);
        $metadata = $this->whitelist->getMetadata($imei) ?? [];
        $supplier = (string)($device['supplier'] ?? ($metadata['supplier'] ?? ''));
        $model = (string)($device['model'] ?? ($metadata['model'] ?? ''));
        $protocol = (string)($device['protocol'] ?? $this->protocolForModel($supplier, $model));

        if ($feature !== '') {
            return $this->sendFeatureCommands($imei, $protocol, $feature, $metadata, $device);
        }

        $entry = DeviceCommandCatalog::requestForProtocol($protocol, $requestId);
        $modelRow = $this->modelForSupplierAndName($supplier, $model);
        if ($entry === null) {
            return ['error' => ['code' => 'unsupported_command', 'message' => 'Command is not supported for this device']];
        }
        if (!$this->isModelRequestEnabled($modelRow, $protocol, $requestId)) {
            return ['error' => ['code' => 'unsupported_for_model', 'message' => 'Command is not enabled for this model']];
        }

        $nativeCommand = (string)($entry['command'] ?? '');
        $bytes = DeviceCommandCatalog::buildDownlink($protocol, $imei, $nativeCommand, ['fields' => $entry['data'] ?? []], [
            'deviceId' => (string)($metadata['deviceId'] ?? $device['deviceId'] ?? ''),
        ]);
        $id = bin2hex(random_bytes(8));
        $status = $this->hub->submitDownlink($imei, $bytes);
        $record = [
            'status' => $status,
            'imei' => $imei,
            'protocol' => $protocol,
            'requestId' => $requestId,
            'nativeType' => $nativeCommand,
            'label' => (string)($entry['label'] ?? $nativeCommand),
            'feature' => (string)($entry['feature'] ?? ''),
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

        return ['status' => $record['status'], 'command' => array_merge($record, ['id' => $id])];
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

    public function saveConfiguration(string $imei, string $body, ?ApiAuthContext $auth = null): array
    {
        if (!$this->canAccessDevice($imei, $auth)) {
            return ['error' => ['code' => 'not_found', 'message' => 'Device was not found']];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['configs']) || !is_array($decoded['configs'])) {
            return ['error' => ['code' => 'invalid_request', 'message' => 'configs object is required']];
        }

        $supplier = trim((string)($decoded['supplier'] ?? ''));
        $model = trim((string)($decoded['model'] ?? ''));
        $results = [];
        foreach ($decoded['configs'] as $key => $payload) {
            if (!is_string($key) || !is_array($payload)) {
                return ['error' => ['code' => 'invalid_config', 'message' => 'Each config entry must be an object']];
            }
            $result = $this->persistAndApplyConfiguration($imei, $key, $payload, $supplier, $model);
            if (isset($result['error'])) {
                return $result;
            }
            $results[] = $result;
        }

        return ['status' => 'ok', 'results' => $results, 'configuration' => $this->configuration($imei, '', $auth)];
    }

    public function applyConfiguration(string $imei, string $key, string $body = '', ?ApiAuthContext $auth = null): array
    {
        if (!$this->canAccessDevice($imei, $auth)) {
            return ['error' => ['code' => 'not_found', 'message' => 'Device was not found']];
        }

        $supplier = '';
        $model = '';
        if ($body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $supplier = trim((string)($decoded['supplier'] ?? ''));
                $model = trim((string)($decoded['model'] ?? ''));
            }
        }
        foreach ($this->db->deviceConfigurations->allForImei($imei) as $row) {
            if (($row['config_key'] ?? '') === $key) {
                return $this->persistAndApplyConfiguration(
                    $imei,
                    $key,
                    $row['desired_payload'] ?? [],
                    $supplier,
                    $model
                );
            }
        }

        return ['error' => ['code' => 'config_not_found', 'message' => 'Desired configuration was not found']];
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
        if ($licenseId === '') {
            return ['error' => ['code' => 'invalid_request', 'message' => 'licenseId is required for NCS and Radars']];
        }
        $deviceId = $this->normalizeDeviceId($imei, $supplier, $model, $deviceId);
        $this->whitelist->register($imei, $supplier, $model, $deviceType, $licenseId, $simNumber, $deviceId, $company);
        $this->store->registerDevice($imei, $supplier, $model, $deviceType, $licenseId, $simNumber, $deviceId, $company);

        return ['status' => 'ok', 'imei' => $imei];
    }

    public function update(string $imei, string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['error' => ['code' => 'invalid_request', 'message' => 'Invalid JSON']];
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
            return ['error' => ['code' => 'invalid_request', 'message' => 'imei, supplier, and model are required']];
        }
        if ($modelRecord === null) {
            return ['error' => ['code' => 'model_not_found', 'message' => 'Model does not exist for this supplier']];
        }
        if ($licenseId === '') {
            return ['error' => ['code' => 'invalid_request', 'message' => 'licenseId is required for NCS and Radars']];
        }
        $deviceId = $this->normalizeDeviceId($newImei, $supplier, $model, $deviceId);
        if ($newImei !== $imei) {
            $this->whitelist->unregister($imei);
            $this->store->deleteDevice($imei);
        }
        $this->whitelist->register($newImei, $supplier, $model, $deviceType, $licenseId, $simNumber, $deviceId, $company);
        $this->store->registerDevice($newImei, $supplier, $model, $deviceType, $licenseId, $simNumber, $deviceId, $company);

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
        if ($company === '' || $licenseId === '' || $licenseId === '0') {
            return ['error' => ['code' => 'invalid_request', 'message' => 'company and licenseId are required']];
        }

        if ($auth !== null && !$auth->isAdmin()) {
            if (!$auth->canAccessLicense($licenseId)) {
                return ['error' => ['code' => 'forbidden', 'message' => 'Forbidden']];
            }

            $currentLicenseId = DeviceMetadata::normalizeLicenseId((string)($existing['licenseId'] ?? '0'));
            $currentCompany = trim((string)($existing['company'] ?? 'null'));
            if ($currentLicenseId !== '0' || $currentCompany !== 'null') {
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
        if ($currentLicenseId === '0' && $currentCompany === 'null') {
            return ['error' => ['code' => 'association_not_found', 'message' => 'Device association was not found']];
        }

        if ($auth !== null && !$auth->isAdmin() && !$auth->canAccessLicense($currentLicenseId)) {
            return ['error' => ['code' => 'not_found', 'message' => 'Device was not found']];
        }

        $this->whitelist->updateAssociation($imei, 'null', '0');
        $this->store->updateDeviceAssociation($imei, 'null', '0');

        return [
            'status' => 'ok',
            'imei' => $imei,
            'association' => [
                'company' => 'null',
                'licenseId' => '0',
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

    public function availableActions(string $imei, ?ApiAuthContext $auth = null): array
    {
        if (!$this->canAccessDevice($imei, $auth)) {
            return ['error' => ['code' => 'not_found', 'message' => 'Device was not found']];
        }

        $device = $this->deviceSnapshot($imei);
        $protocol = (string)($device['protocol'] ?? $this->protocolForModel(
            (string)($device['supplier'] ?? ''),
            (string)($device['model'] ?? '')
        ));
        $model = $this->modelForDevice($device);

        return $this->enabledRequestCommandsForModel($model, $protocol);
    }

    private function pending(string $imei): array
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

    private function isModelRequestEnabled(?array $model, string $protocol, string $requestId): bool
    {
        if ($model === null) {
            return false;
        }

        foreach ($this->enabledRequestCommandsForModel($model, $protocol) as $entry) {
            if ((string)($entry['id'] ?? $entry['command'] ?? '') === $requestId || (string)($entry['command'] ?? '') === $requestId) {
                return true;
            }
        }

        return false;
    }

    private function filterDevices(array $devices, array $filters): array
    {
        return array_values(array_filter($devices, function (array $device) use ($filters): bool {
            $deviceType = DeviceMetadata::normalizeDeviceType((string)($device['deviceType'] ?? 'watch'));
            $licenseId = DeviceMetadata::normalizeLicenseId((string)($device['licenseId'] ?? '0'));
            $supplier = trim((string)($device['supplier'] ?? ''));
            $model = trim((string)($device['model'] ?? ''));
            $query = trim((string)($filters['q'] ?? ''));

            return (($filters['deviceType'] ?? null) === null || $deviceType === $filters['deviceType'])
                && (($filters['licenseId'] ?? null) === null || $licenseId === $filters['licenseId'])
                && (($filters['supplier'] ?? null) === null || $supplier === $filters['supplier'])
                && (($filters['model'] ?? null) === null || $model === $filters['model'])
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

    private function deviceLicenseId(string $imei, array $device): string
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

    private function normalizeLicenseId(string $licenseId, string $deviceType): string
    {
        $normalized = trim($licenseId);

        return $normalized === '' ? '' : $normalized;
    }

    /**
     * @param list<array<string, mixed>> $configRows
     * @return array<string, array<string, mixed>>
     */
    private function deviceCapabilities(?array $model, string $protocol, array $configRows): array
    {
        $catalog = $this->db->genericCapabilities->all();
        $supportedKeys = $model !== null
            ? $this->db->modelCapabilities->enabledFeaturesForModelId((int)($model['id'] ?? 0))
            : GenericModelCapabilityCatalog::keysForProtocol($protocol);
        $matrix = GenericModelCapabilityCatalog::buildCapabilityMatrix($catalog, $supportedKeys);
        $capabilities = [];

        foreach (GenericModelCapabilityCatalog::sections() as $section => $_label) {
            $capabilities[$section] = [];
        }

        foreach ($matrix['telemetry'] ?? [] as $key => $supported) {
            if ($supported) {
                $capabilities['telemetry'][$key] = true;
            }
        }

        $meta = [];
        $nativeKeysPerGeneric = [];

        foreach ($configRows as $row) {
            $nativeKey = trim((string)($row['config_key'] ?? ''));
            $desired = is_array($row['desired_payload'] ?? null) ? $row['desired_payload'] : [];
            if ($nativeKey === '' || $desired === []) {
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

            $normalized = $this->normalizeCapabilityValue($genericKey, $nativeKey, $desired);
            if ($normalized === null) {
                continue;
            }

            $capabilities[$section][$genericKey] = $this->mergeCapabilityValue(
                $genericKey,
                $capabilities[$section][$genericKey] ?? null,
                $normalized
            );

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

        return $capabilities;
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
            'licenseId' => (string)($metadata['licenseId'] ?? '0'),
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
