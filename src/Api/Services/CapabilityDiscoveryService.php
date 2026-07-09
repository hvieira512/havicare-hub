<?php

namespace Hub\Api\Services;

use Hub\Api\Auth\ApiAuthContext;
use Hub\Api\Repository\ApiDataAccess;
use Hub\Api\Repository\CapabilityDiscoveryRepository;
use Hub\Domain\GenericModelCapabilityCatalog;
use Hub\Domain\DeviceMetadata;

final class CapabilityDiscoveryService
{
    public function __construct(
        private ApiDataAccess $db,
        private DeviceService $devices,
        private CapabilityDiscoveryRepository $repository,
    ) {
    }

    public function list(): array
    {
        return [
            'data' => $this->repository->all(),
        ];
    }

    public function show(string $id): array
    {
        $run = $this->repository->find($id);
        if ($run === null) {
            return ['error' => ['code' => 'discovery_not_found', 'message' => 'Discovery run not found']];
        }

        return $run;
    }

    public function preview(string $body, ?ApiAuthContext $auth = null, string $baseUrl = 'http://localhost:8081'): array
    {
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return ['error' => ['code' => 'invalid_request', 'message' => 'Invalid JSON']];
        }

        $imei = trim((string)($payload['imei'] ?? ''));
        $modelId = (int)($payload['modelId'] ?? 0);
        if ($imei === '' || $modelId <= 0) {
            return ['error' => ['code' => 'invalid_request', 'message' => 'imei and modelId are required']];
        }

        $model = $this->db->models->findById($modelId);
        if ($model === null) {
            return ['error' => ['code' => 'model_not_found', 'message' => 'Model not found']];
        }

        $device = $this->devices->show($imei, $auth, $baseUrl);
        if (isset($device['error'])) {
            return $device;
        }

        $deviceModel = $device['model'] ?? [];
        $deviceInfo = $device['device'] ?? [];
        $deviceType = DeviceMetadata::normalizeDeviceType((string)($deviceModel['deviceType'] ?? $model['device_type'] ?? 'watch'));
        $catalog = $this->db->genericCapabilities->all($deviceType);
        $modelEnabled = $this->db->modelCapabilities->enabledFeaturesForModelId($modelId);
        $deviceEnabled = array_values(array_unique(array_map('strval', $device['enabledCapabilityKeys'] ?? [])));
        $suggestedEnabled = $deviceEnabled !== [] ? $deviceEnabled : $modelEnabled;
        $matrix = GenericModelCapabilityCatalog::buildCapabilityMatrix($catalog, $suggestedEnabled);
        $modelCapabilities = GenericModelCapabilityCatalog::buildCapabilityMatrix($catalog, $modelEnabled);
        $evidence = [];

        foreach (GenericModelCapabilityCatalog::definitionsForDeviceType($deviceType) as $definition) {
            $key = (string)($definition['key'] ?? '');
            $section = (string)($definition['section'] ?? '');
            $evidence[] = [
                'section' => $section,
                'key' => $key,
                'label' => (string)($definition['label'] ?? $key),
                'supported' => (bool)($matrix[$section][$key] ?? false),
                'configured' => (bool)($modelCapabilities[$section][$key] ?? false),
                'requestable' => (bool)($definition['isRequestable'] ?? false),
                'telemetry' => (bool)($definition['isTelemetry'] ?? false),
            ];
        }

        $added = array_values(array_diff($suggestedEnabled, $modelEnabled));
        $removed = array_values(array_diff($modelEnabled, $suggestedEnabled));

        $run = [
            'id' => 'disc_' . bin2hex(random_bytes(8)),
            'status' => 'draft',
            'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'device' => [
                'imei' => $imei,
                'online' => (bool)($deviceInfo['online'] ?? false),
                'supplier' => (string)($deviceModel['supplier'] ?? ''),
                'model' => (string)($deviceModel['internalModel'] ?? ''),
                'commercialName' => (string)($deviceModel['commercialName'] ?? ''),
                'deviceType' => $deviceType,
            ],
            'model' => [
                'id' => $modelId,
                'supplier' => (string)($model['supplier_name'] ?? ''),
                'internalModel' => (string)($model['internal_model'] ?? ''),
                'commercialName' => (string)($model['commercial_name'] ?? ''),
                'deviceType' => $deviceType,
            ],
            'currentEnabledCapabilityKeys' => $modelEnabled,
            'suggestedEnabledCapabilityKeys' => $suggestedEnabled,
            'changes' => [
                'add' => $added,
                'remove' => $removed,
            ],
            'evidence' => $evidence,
        ];

        return $this->repository->save($run);
    }

    public function apply(string $id): array
    {
        $run = $this->repository->find($id);
        if ($run === null) {
            return ['error' => ['code' => 'discovery_not_found', 'message' => 'Discovery run not found']];
        }

        $modelId = (int)($run['model']['id'] ?? 0);
        if ($modelId <= 0) {
            return ['error' => ['code' => 'invalid_state', 'message' => 'Discovery run is missing the model id']];
        }

        $keys = array_values(array_map('strval', $run['suggestedEnabledCapabilityKeys'] ?? []));
        $this->db->modelCapabilities->replaceForModelId($modelId, $keys);

        $run['status'] = 'applied';
        $run['appliedAt'] = gmdate('Y-m-d\TH:i:s\Z');

        return $this->repository->save($run);
    }
}
