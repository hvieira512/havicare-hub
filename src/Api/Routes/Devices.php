<?php

namespace Hub\Api\Routes;

use Hub\Api\Support\CollectionResponse;
use Hub\Command\DeviceCommandCatalog;
use Hub\Command\DeviceConfigurationCatalog;
use Hub\Dashboard\DashboardDataAccess;
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

    public function list(string $query = ''): array
    {
        $params = $this->queryParams($query);
        $page = $this->queryPage($params);
        $limit = $this->queryLimit($params, 5);
        $filters = [
            'deviceType' => $this->queryFilter($params, 'deviceType', 'all'),
            'licenseId' => $this->queryFilter($params, 'licenseId', 'all'),
            'supplier' => $this->queryFilter($params, 'supplier', 'all'),
            'model' => $this->queryFilter($params, 'model', 'all'),
            'q' => $this->queryFilter($params, 'q', ''),
        ];
        $devices = $this->store->devices();
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
        ];

        return $this->collectionResponse($filtered, $page, $limit, $filters, $available);
    }

    public function show(string $imei): array
    {
        $device = $this->store->device($imei);
        $protocol = (string)($device['protocol'] ?? $this->protocolForModel((string)($device['supplier'] ?? ''), (string)($device['model'] ?? '')));
        $model = $this->modelForDevice($device);

        return [
            'device' => $device,
            'commands' => $this->enabledRequestCommandsForModel($model, $protocol),
            'configuration' => [
                'supported' => count(DeviceConfigurationCatalog::configsForProtocol($protocol)),
                'stored' => count($this->db->deviceConfigurations->allForImei($imei)),
            ],
            'pending' => $this->pending($imei),
            'recent' => [
                'raw' => $this->store->recent($imei, 'raw'),
                'telemetry' => $this->store->recent($imei, 'telemetry'),
                'events' => $this->store->recent($imei, 'events'),
                'commands' => $this->store->commands($imei),
            ],
        ];
    }

    public function command(string $imei, string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !is_string($decoded['command'] ?? null)) {
            return ['error' => ['code' => 'invalid_request', 'message' => 'command is required']];
        }

        $device = $this->store->device($imei);
        $metadata = $this->whitelist->getMetadata($imei) ?? [];
        $supplier = (string)($device['supplier'] ?? ($metadata['supplier'] ?? ''));
        $model = (string)($device['model'] ?? ($metadata['model'] ?? ''));
        $protocol = (string)($device['protocol'] ?? $this->protocolForModel($supplier, $model));
        $command = (string)$decoded['command'];
        $modelRow = $this->modelForSupplierAndName($supplier, $model);
        $entry = DeviceCommandCatalog::commandForProtocol($protocol, $command);
        if ($entry === null) {
            return ['error' => ['code' => 'unsupported_command', 'message' => 'Command is not supported for this device']];
        }
        if (!$this->isModelRequestEnabled($modelRow, $protocol, $command)) {
            return ['error' => ['code' => 'unsupported_for_model', 'message' => 'Command is not enabled for this model']];
        }

        $bytes = DeviceCommandCatalog::buildDownlink($protocol, $imei, $command, [], [
            'deviceId' => (string)($metadata['deviceId'] ?? $device['deviceId'] ?? ''),
        ]);
        $id = bin2hex(random_bytes(8));
        $status = $this->hub->submitDownlink($imei, $bytes);
        $record = [
            'status' => $status,
            'imei' => $imei,
            'protocol' => $protocol,
            'nativeType' => $command,
            'label' => (string)($entry['label'] ?? $command),
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

    public function configuration(string $imei, string $query = ''): array
    {
        $device = $this->store->device($imei);
        $metadata = $this->whitelist->getMetadata($imei) ?? [];
        $queryParams = [];
        if ($query !== '') {
            parse_str($query, $queryParams);
        }
        $supplier = trim((string)($queryParams['supplier'] ?? '')) !== ''
            ? trim((string)$queryParams['supplier'])
            : (string)($device['supplier'] ?? ($metadata['supplier'] ?? ''));
        $model = trim((string)($queryParams['model'] ?? '')) !== ''
            ? trim((string)$queryParams['model'])
            : (string)($device['model'] ?? ($metadata['model'] ?? ''));
        $protocol = $this->protocolForModel($supplier, $model);
        if ($protocol === '') {
            $protocol = (string)($device['protocol'] ?? '');
        }

        $catalog = array_map(static fn (array $entry): array => array_filter([
            'key' => $entry['key'],
            'command' => $entry['command'],
            'label' => $entry['label'],
            'input' => $entry['input'],
            'fields' => $entry['fields'],
            'expectedReplyTypes' => $entry['expectedReplyTypes'],
            'category' => $entry['category'],
            'limit' => $entry['limit'] ?? null,
        ], static fn (mixed $value): bool => $value !== null), DeviceConfigurationCatalog::configsForProtocol($protocol));

        $configurations = [];
        foreach ($this->db->deviceConfigurations->allForImei($imei) as $row) {
            $reported = $row['reported_payload'];
            $reportedData = is_array($reported) ? ($reported['data'] ?? null) : null;
            $configurations[$row['config_key']] = array_filter([
                'desired' => $row['desired_payload'] ?: null,
                'reported' => $reportedData !== null && is_array($reportedData) ? $reportedData : ($reported ?: null),
            ], static fn (mixed $value): bool => $value !== null);
        }

        return [
            'device' => array_filter([
                'imei' => $device['imei'] ?? $imei,
                'supplier' => $device['supplier'] ?? $supplier,
                'model' => $device['model'] ?? $model,
                'protocol' => $protocol,
                'online' => (bool)($device['online'] ?? false),
                'deviceType' => $device['deviceType'] ?? 'watch',
                'licenseId' => $device['licenseId'] ?? '0',
                'simNumber' => $device['simNumber'] ?? '',
                'deviceId' => $device['deviceId'] ?? '',
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            'catalog' => $catalog,
            'configurations' => $configurations,
        ];
    }

    public function saveConfiguration(string $imei, string $body): array
    {
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

        $query = http_build_query(array_filter([
            'supplier' => $supplier,
            'model' => $model,
        ], static fn (string $value): bool => $value !== ''));

        return ['status' => 'ok', 'results' => $results, 'configuration' => $this->configuration($imei, $query)];
    }

    public function applyConfiguration(string $imei, string $key, string $body = ''): array
    {
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
        $deviceType = DeviceMetadata::normalizeDeviceType((string)($decoded['deviceType'] ?? 'watch'));
        $licenseId = $this->normalizeLicenseId((string)($decoded['licenseId'] ?? '0'), $deviceType);
        $simNumber = trim((string)($decoded['simNumber'] ?? ''));
        $deviceId = trim((string)($decoded['deviceId'] ?? $decoded['device_id'] ?? ''));
        $sourceSystem = trim((string)($decoded['sourceSystem'] ?? $decoded['source_system'] ?? ''));
        $sourceDeviceId = trim((string)($decoded['sourceDeviceId'] ?? $decoded['source_device_id'] ?? ''));
        if ($imei === '' || $supplier === '' || $model === '') {
            return ['error' => ['code' => 'invalid_request', 'message' => 'imei, supplier, and model are required']];
        }
        if ($licenseId === '') {
            return ['error' => ['code' => 'invalid_request', 'message' => 'licenseId is required for NCS and Radars']];
        }
        $this->whitelist->register($imei, $supplier, $model, $deviceType, $licenseId, $simNumber, $deviceId, $sourceSystem, $sourceDeviceId);
        $this->store->registerDevice($imei, $supplier, $model, $deviceType, $licenseId, $simNumber, $deviceId, $sourceSystem, $sourceDeviceId);

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
        $deviceType = DeviceMetadata::normalizeDeviceType((string)($decoded['deviceType'] ?? 'watch'));
        $licenseId = $this->normalizeLicenseId((string)($decoded['licenseId'] ?? '0'), $deviceType);
        $simNumber = trim((string)($decoded['simNumber'] ?? ''));
        $deviceId = trim((string)($decoded['deviceId'] ?? $decoded['device_id'] ?? ''));
        $sourceSystem = trim((string)($decoded['sourceSystem'] ?? $decoded['source_system'] ?? ''));
        $sourceDeviceId = trim((string)($decoded['sourceDeviceId'] ?? $decoded['source_device_id'] ?? ''));
        if ($newImei === '' || $supplier === '' || $model === '') {
            return ['error' => ['code' => 'invalid_request', 'message' => 'imei, supplier, and model are required']];
        }
        if ($licenseId === '') {
            return ['error' => ['code' => 'invalid_request', 'message' => 'licenseId is required for NCS and Radars']];
        }
        if ($newImei !== $imei) {
            $this->whitelist->unregister($imei);
            $this->store->deleteDevice($imei);
        }
        $this->whitelist->register($newImei, $supplier, $model, $deviceType, $licenseId, $simNumber, $deviceId, $sourceSystem, $sourceDeviceId);
        $this->store->registerDevice($newImei, $supplier, $model, $deviceType, $licenseId, $simNumber, $deviceId, $sourceSystem, $sourceDeviceId);

        return ['status' => 'ok', 'imei' => $newImei];
    }

    public function delete(string $imei): array
    {
        $this->whitelist->unregister($imei);
        $this->store->deleteDevice($imei);

        return ['status' => 'ok', 'imei' => $imei];
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
        $device = $this->store->device($imei);
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
        return $this->db->models->protocolForModel($supplier, $model);
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

        $enabled = array_flip($this->db->modelRequestCapabilities->enabledCommandsForModelId((int)$model['id']));

        return array_values(array_filter(
            $commands,
            static fn(array $entry): bool => isset($enabled[(string)($entry['command'] ?? '')])
        ));
    }

    private function isModelRequestEnabled(?array $model, string $protocol, string $command): bool
    {
        if ($model === null) {
            return false;
        }

        foreach ($this->enabledRequestCommandsForModel($model, $protocol) as $entry) {
            if ((string)($entry['command'] ?? '') === $command) {
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

            return (($filters['deviceType'] ?? 'all') === 'all' || $deviceType === $filters['deviceType'])
                && (($filters['licenseId'] ?? 'all') === 'all' || $licenseId === $filters['licenseId'])
                && (($filters['supplier'] ?? 'all') === 'all' || $supplier === $filters['supplier'])
                && (($filters['model'] ?? 'all') === 'all' || $model === $filters['model'])
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
        $candidateFilters[$excludeKey] = 'all';

        return $this->filterDevices($devices, $candidateFilters);
    }

    private function normalizeLicenseId(string $licenseId, string $deviceType): string
    {
        if ($deviceType === 'watch') {
            return '0';
        }

        $normalized = trim($licenseId);

        return $normalized === '' ? '' : $normalized;
    }
}
