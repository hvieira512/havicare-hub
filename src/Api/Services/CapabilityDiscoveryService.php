<?php

declare(strict_types=1);

namespace Hub\Api\Services;

use Hub\Api\Auth\ApiAuthContext;
use Hub\Api\Http\ApiError;
use Hub\Api\Http\CollectionPresenter;
use Hub\Api\Http\DiscoveryColumns;
use Hub\Api\Request\CapabilityDiscoveryRequest;
use Hub\Api\Request\RequestBinder;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Infrastructure\Persistence\Repository\CapabilityDiscoveryRepository;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\DeviceMetadata;

final class CapabilityDiscoveryService
{
    private const DEFAULT_COLLECTION_LIMIT = 20;

    private CollectionPresenter $presenter;

    public function __construct(
        private ApiDataAccess $db,
        private DeviceService $devices,
        private CapabilityDiscoveryRepository $repository,
    ) {
        $this->presenter = new CollectionPresenter();
    }

    /** @return array<string, mixed> */
    public function list(string $query = ''): array
    {
        return $this->presenter->present(
            $this->repository->all(),
            DiscoveryColumns::definition(),
            $this->presenter->params($query),
            self::DEFAULT_COLLECTION_LIMIT,
        );
    }

    /** @return array<string, mixed> */
    public function show(string $id): array
    {
        $run = $this->repository->find($id);
        if ($run === null) {
            return ApiError::discoveryNotFound()->toArray();
        }

        return $run;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function preview(array $payload, ?ApiAuthContext $auth = null, string $baseUrl = 'http://localhost:8081'): array
    {
        $request = (new RequestBinder())->bind($payload, CapabilityDiscoveryRequest::class, coerceStrings: true);
        if (is_array($request)) {
            return $request;
        }

        $imei = trim((string)$request->imei);
        $modelId = (int)$request->modelId;

        $model = $this->db->models->findById($modelId);
        if ($model === null) {
            return ApiError::modelNotFound()->toArray();
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
        $matrix = CapabilityCatalog::buildCapabilityMatrix($catalog, $suggestedEnabled);
        $modelCapabilities = CapabilityCatalog::buildCapabilityMatrix($catalog, $modelEnabled);
        $evidence = [];

        foreach (CapabilityCatalog::definitionsForDeviceType($deviceType) as $definition) {
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

    /** @return array<string, mixed> */
    public function apply(string $id): array
    {
        $run = $this->repository->find($id);
        if ($run === null) {
            return ApiError::discoveryNotFound()->toArray();
        }

        $modelId = (int)($run['model']['id'] ?? 0);
        if ($modelId <= 0) {
            return ApiError::discoveryMissingModelId()->toArray();
        }

        $keys = array_values(array_map('strval', $run['suggestedEnabledCapabilityKeys'] ?? []));
        $this->db->modelCapabilities->replaceForModelId($modelId, $keys);

        $run['status'] = 'applied';
        $run['appliedAt'] = gmdate('Y-m-d\TH:i:s\Z');

        return $this->repository->save($run);
    }
}
