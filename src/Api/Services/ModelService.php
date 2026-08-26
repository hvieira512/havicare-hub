<?php

namespace Hub\Api\Services;

use Hub\Api\Http\CollectionQuery;
use Hub\Api\Http\CollectionResponder;
use Hub\Api\Http\ModelImageUrl;
use Hub\Api\Repository\ApiDataAccess;
use Hub\Command\DeviceCommandCatalog;
use Hub\Domain\DeviceProtocol;
use Hub\Domain\DeviceMetadata;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\SupplierCapabilityTemplate;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

class ModelService
{
    private const MODEL_IMAGE_DIR = __DIR__ . '/../../../var/dashboard/model-images';
    private const MODEL_IMAGE_ROUTE = '/model-images';
    private const MAX_MODEL_IMAGE_BYTES = 5 * 1024 * 1024;
    private const MAX_MODEL_IMAGE_DIMENSION = 640;
    private const DEFAULT_COLLECTION_LIMIT = 20;

    private CollectionQuery $query;
    private CollectionResponder $collection;
    private ModelImageUrl $imageUrl;

    public function __construct(
        private ApiDataAccess $db,
        ?CollectionQuery $query = null,
        ?CollectionResponder $collection = null,
        ?ModelImageUrl $imageUrl = null,
    ) {
        $this->query = $query ?? new CollectionQuery();
        $this->collection = $collection ?? new CollectionResponder();
        $this->imageUrl = $imageUrl ?? new ModelImageUrl();
    }

    public function list(string $query = '', string $baseUrl = ''): array
    {
        $params = $this->query->params($query);
        $page = $this->query->page($params);
        $limit = $this->query->limit($params, self::DEFAULT_COLLECTION_LIMIT);
        $filters = [
            'supplier' => $this->query->filter($params, 'supplier'),
            'protocol' => $this->query->filter($params, 'protocol'),
            'deviceType' => $this->query->filter($params, 'deviceType'),
            'model' => $this->query->filter($params, 'model'),
        ];
        $models = array_values(array_filter($this->db->models->all(), static function (array $model) use ($filters): bool {
            $supplierRaw = trim((string)($model['supplier'] ?? ''));
            $supplier = mb_strtolower($supplierRaw);
            $protocol = mb_strtolower(DeviceProtocol::forModel($supplierRaw, (string)($model['internal_model'] ?? '')));
            $deviceType = mb_strtolower(trim((string)($model['device_type'] ?? 'watch')));
            $internalModel = mb_strtolower(trim((string)($model['internal_model'] ?? '')));
            $commercialName = mb_strtolower(trim((string)($model['commercial_name'] ?? '')));

            foreach ($filters as $key => $value) {
                if ($value === null) {
                    continue;
                }
                $needle = mb_strtolower($value);
                $haystack = match ($key) {
                    'supplier' => $supplier,
                    'protocol' => $protocol,
                    'deviceType' => $deviceType,
                    'model' => $internalModel . "\0" . $commercialName,
                    default => '',
                };
                if (!str_contains($haystack, $needle)) {
                    return false;
                }
            }

            return true;
        }));
        $available = [
            'supplier' => $this->collection->uniqueValues(array_map(
                static fn (array $model): string => trim((string)($model['supplier'] ?? '')),
                array_values(array_filter($this->db->models->all(), static function (array $model) use ($filters): bool {
                    $protocol = DeviceProtocol::forModel((string)($model['supplier'] ?? ''), (string)($model['internal_model'] ?? ''));
                    $deviceType = trim((string)($model['device_type'] ?? 'watch'));
                    return (($filters['protocol'] ?? null) === null || $protocol === $filters['protocol'])
                        && (($filters['deviceType'] ?? null) === null || $deviceType === $filters['deviceType']);
                }))
            )),
            'protocol' => $this->collection->uniqueValues(array_map(
                static fn (array $model): string => DeviceProtocol::forModel((string)($model['supplier'] ?? ''), (string)($model['internal_model'] ?? '')),
                array_values(array_filter($this->db->models->all(), static function (array $model) use ($filters): bool {
                    $supplier = trim((string)($model['supplier'] ?? ''));
                    $deviceType = trim((string)($model['device_type'] ?? 'watch'));
                    return (($filters['supplier'] ?? null) === null || $supplier === $filters['supplier'])
                        && (($filters['deviceType'] ?? null) === null || $deviceType === $filters['deviceType']);
                }))
            )),
            'deviceType' => $this->collection->uniqueValues(array_map(
                static fn (array $model): string => trim((string)($model['device_type'] ?? 'watch')),
                array_values(array_filter($this->db->models->all(), static function (array $model) use ($filters): bool {
                    $supplier = trim((string)($model['supplier'] ?? ''));
                    $protocol = DeviceProtocol::forModel((string)($model['supplier'] ?? ''), (string)($model['internal_model'] ?? ''));
                    return (($filters['supplier'] ?? null) === null || $supplier === $filters['supplier'])
                        && (($filters['protocol'] ?? null) === null || $protocol === $filters['protocol']);
                }))
            )),
            'model' => $this->collection->uniqueValues(array_merge(
                array_map(static fn (array $m): string => trim((string)($m['internal_model'] ?? '')), $this->db->models->all()),
                array_map(static fn (array $m): string => trim((string)($m['commercial_name'] ?? '')), $this->db->models->all()),
            )),
        ];

        $catalogByType = [];
        $enabledByModel = $this->db->modelCapabilities->enabledFeaturesForModelIds(array_map(
            static fn(array $model): int => (int)($model['id'] ?? 0),
            $models
        ));
        $models = array_map(function (array $model) use ($enabledByModel, &$catalogByType, $baseUrl): array {
            $modelId = (int)($model['id'] ?? 0);
            $protocol = DeviceProtocol::forModel((string)($model['supplier'] ?? ''), (string)($model['internal_model'] ?? ''));
            $deviceType = (string)($model['device_type'] ?? 'watch');
            $catalogByType[$deviceType] ??= $this->db->genericCapabilities->all($deviceType);
            return [
                'id' => $modelId,
                'supplier_id' => (int)($model['supplier_id'] ?? 0),
                'supplier' => (string)($model['supplier'] ?? ''),
                'internalModel' => (string)($model['internal_model'] ?? ''),
                'commercialName' => (string)($model['commercial_name'] ?? ''),
                'deviceType' => $deviceType,
                'protocol' => $protocol,
                'image' => $this->imageUrl->resolve((string)($model['image'] ?? ''), $baseUrl),
                'capabilities' => CapabilityCatalog::buildCapabilityMatrix($catalogByType[$deviceType], $enabledByModel[$modelId] ?? []),
            ];
        }, $models);

        return $this->collection->respond($models, $page, $limit, $filters, $available);
    }

    public function filters(): array
    {
        $groups = [];
        foreach (CapabilityCatalog::deviceTypes() as $deviceType) {
            $groups[$deviceType] = [
                'deviceType' => $deviceType,
                'suppliers' => [],
            ];
        }

        foreach ($this->db->supplierDeviceTypes->all() as $row) {
            $deviceType = DeviceMetadata::normalizeDeviceType((string)($row['device_type'] ?? 'watch'));
            if (!isset($groups[$deviceType])) {
                continue;
            }

            $groups[$deviceType]['suppliers'][] = [
                'id' => (int)($row['supplier_id'] ?? 0),
                'name' => (string)($row['supplier'] ?? ''),
            ];
        }

        return [
            'data' => array_values($groups),
        ];
    }

    public function deviceTypeSuppliersModels(string $baseUrl = ''): array
    {
        $groups = [];
        foreach (CapabilityCatalog::deviceTypes() as $deviceType) {
            $groups[$deviceType] = [
                'deviceType' => $deviceType,
                'suppliers' => [],
            ];
        }

        $modelsByDeviceTypeAndSupplier = [];
        foreach ($this->db->models->all() as $model) {
            $deviceType = DeviceMetadata::normalizeDeviceType((string)($model['device_type'] ?? 'watch'));
            $supplierName = (string)($model['supplier'] ?? '');
            $modelsByDeviceTypeAndSupplier[$deviceType][$supplierName][] = [
                'id' => (int)($model['id'] ?? 0),
                'supplier_id' => (int)($model['supplier_id'] ?? 0),
                'supplier' => $supplierName,
                'internalModel' => (string)($model['internal_model'] ?? ''),
                'commercialName' => (string)($model['commercial_name'] ?? ''),
                'deviceType' => $deviceType,
                'protocol' => DeviceProtocol::forModel($supplierName, (string)($model['internal_model'] ?? '')),
                'image' => $this->imageUrl->resolve((string)($model['image'] ?? ''), $baseUrl),
            ];
        }

        foreach ($groups as $deviceType => &$group) {
            $suppliersForDeviceType = $modelsByDeviceTypeAndSupplier[$deviceType] ?? [];
            foreach ($suppliersForDeviceType as $supplierName => $models) {
                $firstModel = $models[0] ?? [];
                $group['suppliers'][] = [
                    'id' => (int)($firstModel['supplier_id'] ?? 0),
                    'name' => $supplierName,
                    'models' => $models,
                ];
            }
        }
        unset($group);

        return [
            'data' => array_values($groups),
        ];
    }

    public function show(int $id, string $baseUrl = ''): array
    {
        $entry = $this->db->models->findById($id);
        if ($entry === null) {
            return ['error' => ['code' => 'model_not_found', 'message' => 'Model not found']];
        }

        $protocol = DeviceProtocol::forModel((string)($entry['supplier_name'] ?? ''), (string)($entry['internal_model'] ?? ''));
        $imagePath = (string)($entry['image_path'] ?? '');
        $deviceType = (string)($entry['device_type'] ?? 'watch');
        $catalog = $this->db->genericCapabilities->all($deviceType);
        $requestableCapabilityKeys = $this->requestableTelemetryKeys(
            $protocol,
            (string)($entry['supplier_name'] ?? ''),
            $deviceType
        );
        $effectiveRequestable = array_values(array_intersect(
            $this->db->modelCapabilities->requestableTelemetryFeaturesForModelId($id),
            $requestableCapabilityKeys
        ));

        return [
            'id' => $id,
            'supplier_id' => (int)($entry['supplier_id'] ?? 0),
            'supplier' => (string)($entry['supplier_name'] ?? ''),
            'internalModel' => (string)($entry['internal_model'] ?? ''),
            'commercialName' => (string)($entry['commercial_name'] ?? ''),
            'deviceType' => $deviceType,
            'protocol' => $protocol,
            'image' => $this->imageUrl->resolve($imagePath, $baseUrl),
            'capabilities' => CapabilityCatalog::buildCapabilityMatrix(
                $catalog,
                $this->db->modelCapabilities->enabledFeaturesForModelId($id)
            ),
            'requestableCapabilityKeys' => $requestableCapabilityKeys,
            'requestableCapabilities' => $effectiveRequestable,
        ];
    }

    public function template(string $query = ''): array
    {
        $params = $this->query->params($query);
        $supplierId = (int)($params['supplierId'] ?? $params['supplier_id'] ?? 0);
        $deviceType = DeviceMetadata::normalizeDeviceType((string)($params['deviceType'] ?? $params['device_type'] ?? 'watch'));
        if ($supplierId <= 0) {
            return ['error' => ['code' => 'invalid_request', 'message' => 'supplierId is required']];
        }

        $supplier = $this->db->suppliers->findById($supplierId);
        if ($supplier === null) {
            return ['error' => ['code' => 'supplier_not_found', 'message' => 'Supplier does not exist']];
        }

        $supplierName = (string)($supplier['name'] ?? '');
        $protocol = DeviceProtocol::forSupplier($supplierName);
        if ($protocol === '') {
            return ['error' => ['code' => 'unknown_protocol', 'message' => 'Could not determine protocol for this supplier']];
        }

        $catalog = $this->db->genericCapabilities->all($deviceType);
        $enabledKeys = SupplierCapabilityTemplate::keysForSupplierDeviceType($supplierName, $deviceType);

        return [
            'supplier_id' => $supplierId,
            'supplier' => $supplierName,
            'deviceType' => $deviceType,
            'protocol' => $protocol,
            'enabledCapabilities' => $enabledKeys,
            'requestableCapabilityKeys' => $this->requestableTelemetryKeys(
                $protocol,
                $supplierName,
                $deviceType
            ),
            'capabilities' => CapabilityCatalog::buildCapabilityMatrix($catalog, $enabledKeys),
        ];
    }

    public function create(ServerRequestInterface $request): array
    {
        $payload = $this->modelPayload($request, 'create');
        if (isset($payload['error'])) {
            return $payload;
        }
        $supplierId = (int)$payload['supplier_id'];
        $internalModel = (string)$payload['internal_model'];
        $commercialName = (string)$payload['commercial_name'];
        $deviceType = (string)$payload['device_type'];
        $enabledCapabilities = $payload['enabled_capabilities'];
        $requestableCapabilities = $payload['requestable_capabilities'];

        $imagePath = $this->storeModelImage($request->getUploadedFiles()['image'] ?? null);
        if (is_array($imagePath)) {
            return $imagePath;
        }
        $supplier = $this->db->suppliers->findById($supplierId);
        $previousImagePath = null;
        if (is_string($imagePath) && is_array($supplier)) {
            $existing = $this->db->models->find((string)$supplier['name'], $internalModel);
            $previousImagePath = is_array($existing) ? (string)($existing['image_path'] ?? '') : null;
        }
        $this->db->models->add($supplierId, $internalModel, $commercialName, $deviceType, $imagePath);
        $supplier = $this->db->suppliers->findById($supplierId);
        $stored = is_array($supplier) ? $this->db->models->find((string)$supplier['name'], $internalModel) : null;
        if (is_array($stored)) {
            $this->db->modelCapabilities->replaceForModelId((int)$stored['id'], $enabledCapabilities);
            if (is_array($requestableCapabilities)) {
                $this->db->modelCapabilities->replaceTelemetryRequestabilityForModelId(
                    (int)$stored['id'],
                    $requestableCapabilities
                );
            }
        }
        if (is_string($imagePath) && $previousImagePath !== null && $previousImagePath !== $imagePath) {
            $this->deleteStoredModelImage($previousImagePath);
        }

        return ['status' => 'ok'];
    }

    public function update(int $id, ServerRequestInterface $request): array
    {
        $current = $this->db->models->findById($id);
        if ($current === null) {
            return ['error' => ['code' => 'model_not_found', 'message' => 'Model not found']];
        }

        $payload = $this->modelPayload($request, 'update', $id);
        if (isset($payload['error'])) {
            return $payload;
        }
        $supplierId = (int)$payload['supplier_id'];
        $internalModel = (string)$payload['internal_model'];
        $commercialName = (string)$payload['commercial_name'];
        $deviceType = (string)$payload['device_type'];
        $enabledCapabilities = $payload['enabled_capabilities'];
        $requestableCapabilities = $payload['requestable_capabilities'];
        if ($this->db->models->existsForDifferentId($id, $supplierId, $internalModel)) {
            return ['error' => ['code' => 'model_exists', 'message' => 'Another model with this supplier and model name already exists']];
        }

        $imagePath = $this->storeModelImage($request->getUploadedFiles()['image'] ?? null);
        if (is_array($imagePath)) {
            return $imagePath;
        }

        $this->db->models->update($id, $supplierId, $internalModel, $commercialName, $deviceType, $imagePath);
        $this->db->modelCapabilities->replaceForModelId($id, $enabledCapabilities);
        if (is_array($requestableCapabilities)) {
            $this->db->modelCapabilities->replaceTelemetryRequestabilityForModelId($id, $requestableCapabilities);
        }
        if (is_string($imagePath)) {
            $this->deleteStoredModelImage((string)($current['image_path'] ?? ''));
        }

        return ['status' => 'ok'];
    }

    public function delete(int $id): array
    {
        $model = $this->db->models->findById($id);
        $this->db->models->delete($id);
        if (is_array($model)) {
            $this->deleteStoredModelImage((string)($model['image_path'] ?? ''));
        }

        return ['status' => 'ok'];
    }

    /**
     * @return string|array<string, array<string, string>>|null
     */
    public function storeModelImage(mixed $upload): string|array|null
    {
        if (!$upload instanceof UploadedFileInterface || $upload->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            return ['error' => ['code' => 'upload_failed', 'message' => 'Image upload failed']];
        }
        if (($upload->getSize() ?? 0) > self::MAX_MODEL_IMAGE_BYTES) {
            return ['error' => ['code' => 'image_too_large', 'message' => 'Model image must be 5 MB or smaller']];
        }
        if (!function_exists('imagecreatefromstring')) {
            return ['error' => ['code' => 'gd_missing', 'message' => 'PHP GD extension is required to compress model images']];
        }
        if (!function_exists('imagejpeg')) {
            return ['error' => ['code' => 'gd_jpeg_missing', 'message' => 'PHP GD JPEG support is required to save compressed model images']];
        }

        $stream = $upload->getStream();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $bytes = $stream->getContents();
        if ($bytes === '') {
            return null;
        }

        $source = @\imagecreatefromstring($this->stripPngColorProfiles($bytes));
        if ($source === false) {
            return ['error' => ['code' => 'invalid_image', 'message' => 'Model image must be a valid image file']];
        }

        $width = \imagesx($source);
        $height = \imagesy($source);
        $scale = min(1, self::MAX_MODEL_IMAGE_DIMENSION / max($width, $height));
        $targetWidth = max(1, (int)round($width * $scale));
        $targetHeight = max(1, (int)round($height * $scale));
        $target = \imagecreatetruecolor($targetWidth, $targetHeight);
        $white = \imagecolorallocate($target, 255, 255, 255);
        \imagefill($target, 0, 0, $white);
        \imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        if (!is_dir(self::MODEL_IMAGE_DIR)) {
            mkdir(self::MODEL_IMAGE_DIR, 0755, true);
        }
        $filename = bin2hex(random_bytes(16)) . '.jpg';
        $path = self::MODEL_IMAGE_DIR . '/' . $filename;
        $saved = \imagejpeg($target, $path, 78);

        if (!$saved) {
            return ['error' => ['code' => 'image_save_failed', 'message' => 'Could not save model image']];
        }

        return self::MODEL_IMAGE_ROUTE . '/' . $filename;
    }

    private function modelPayload(ServerRequestInterface $request, string $mode, ?int $modelId = null): array
    {
        $decoded = $this->modelRequestData($request);
        if (!is_array($decoded)) {
            return ['error' => ['code' => 'invalid_request', 'message' => 'Invalid JSON']];
        }
        $supplierId = (int)($decoded['supplier_id'] ?? 0);
        $internalModel = trim((string)($decoded['internalModel'] ?? $decoded['internal_model'] ?? ''));
        $commercialName = trim((string)($decoded['commercialName'] ?? $decoded['commercial_name'] ?? ''));
        $deviceType = DeviceMetadata::normalizeDeviceType((string)($decoded['deviceType'] ?? $decoded['device_type'] ?? 'watch'));
        if ($supplierId <= 0 || $internalModel === '' || $commercialName === '') {
            return ['error' => ['code' => 'invalid_request', 'message' => 'supplier_id, internalModel, and commercialName are required']];
        }
        $supplier = $this->db->suppliers->findById($supplierId);
        if ($supplier === null) {
            return ['error' => ['code' => 'supplier_not_found', 'message' => 'Supplier does not exist']];
        }

        $supplierName = (string)($supplier['name'] ?? '');
        $protocol = DeviceProtocol::forSupplier($supplierName);
        if ($protocol === '') {
            return ['error' => ['code' => 'unknown_protocol', 'message' => 'Could not determine protocol for this supplier']];
        }

        $defaultFeatures = SupplierCapabilityTemplate::keysForSupplierDeviceType($supplierName, $deviceType);
        $defaultFeatureSet = array_flip($defaultFeatures);
        $hasEnabledCapabilitiesSelection = array_key_exists('capabilitiesConfigured', $decoded)
            || array_key_exists('capabilities', $decoded)
            || array_key_exists('capabilities[]', $decoded)
            || array_key_exists('enabledCapabilitiesConfigured', $decoded)
            || array_key_exists('enabledCapabilities', $decoded)
            || array_key_exists('enabledCapabilities[]', $decoded);
        $enabledCapabilities = $this->featureValues(
            $decoded['capabilities']
            ?? $decoded['capabilities[]']
            ?? $decoded['enabledCapabilities']
            ?? $decoded['enabledCapabilities[]']
            ?? null
        );
        if (!$hasEnabledCapabilitiesSelection) {
            if ($mode === 'update' && $modelId !== null) {
                $current = $this->db->models->findById($modelId);
                $sameSupplier = (int)($current['supplier_id'] ?? 0) === $supplierId;
                $sameDeviceType = DeviceMetadata::normalizeDeviceType((string)($current['device_type'] ?? 'watch')) === $deviceType;
                $enabledCapabilities = $sameSupplier && $sameDeviceType
                    ? array_values(array_filter(
                        $this->db->modelCapabilities->enabledFeaturesForModelId($modelId),
                        static fn(string $feature): bool => isset($defaultFeatureSet[$feature]),
                    ))
                    : $defaultFeatures;
            } else {
                $enabledCapabilities = $defaultFeatures;
            }
        } else {
            $unsupported = array_values(array_filter($enabledCapabilities, static fn(string $f): bool => !isset($defaultFeatureSet[$f])));
            if ($unsupported !== []) {
                return ['error' => ['code' => 'unsupported_capability', 'message' => 'One or more capabilities are not allowed for this device type']];
            }
        }

        $hasRequestableCapabilitiesSelection = array_key_exists('requestableCapabilitiesConfigured', $decoded)
            || array_key_exists('requestableCapabilities', $decoded)
            || array_key_exists('requestableCapabilities[]', $decoded);
        $requestableCapabilities = $this->featureValues(
            $decoded['requestableCapabilities']
            ?? $decoded['requestableCapabilities[]']
            ?? null
        );
        if ($hasRequestableCapabilitiesSelection) {
            $enabledSet = array_fill_keys($enabledCapabilities, true);
            $requestableCatalog = array_fill_keys(
                $this->requestableTelemetryKeys($protocol, $supplierName, $deviceType),
                true
            );
            $invalid = array_values(array_filter(
                $requestableCapabilities,
                static fn(string $feature): bool =>
                    !isset($enabledSet[$feature]) || !isset($requestableCatalog[$feature])
            ));
            if ($invalid !== []) {
                return ['error' => [
                    'code' => 'invalid_requestable_capability',
                    'message' => 'Requestable telemetry must also be supported and requestable in the capability catalog',
                ]];
            }
        }

        $capabilityIds = $this->capabilityIdsForDeviceTypeAndKeys($deviceType, $enabledCapabilities);
        $requestableCapabilityIds = $hasRequestableCapabilitiesSelection
            ? $this->capabilityIdsForDeviceTypeAndKeys($deviceType, $requestableCapabilities)
            : null;

        return [
            'supplier_id' => $supplierId,
            'internal_model' => $internalModel,
            'commercial_name' => $commercialName,
            'device_type' => $deviceType,
            'enabled_capabilities' => $capabilityIds,
            'requestable_capabilities' => $requestableCapabilityIds,
        ];
    }

    private function modelRequestData(ServerRequestInterface $request): ?array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed)) {
            return $parsed;
        }

        $decoded = json_decode((string)$request->getBody(), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return list<string>
     */
    private function featureValues(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $item) {
            $feature = trim((string)$item);
            if ($feature === '') {
                continue;
            }
            $normalized[$feature] = true;
        }

        return array_keys($normalized);
    }

    private function stripPngColorProfiles(string $bytes): string
    {
        $signature = "\x89PNG\r\n\x1a\n";
        if (!str_starts_with($bytes, $signature)) {
            return $bytes;
        }

        $offset = strlen($signature);
        $length = strlen($bytes);
        $clean = $signature;
        $removed = false;

        while ($offset + 12 <= $length) {
            $chunkLength = unpack('N', substr($bytes, $offset, 4))[1];
            $chunkEnd = $offset + 12 + $chunkLength;
            if ($chunkLength < 0 || $chunkEnd > $length) {
                return $bytes;
            }

            $chunkType = substr($bytes, $offset + 4, 4);
            if ($chunkType !== 'iCCP') {
                $clean .= substr($bytes, $offset, 12 + $chunkLength);
            } else {
                $removed = true;
            }

            $offset = $chunkEnd;
            if ($chunkType === 'IEND') {
                break;
            }
        }

        return $removed ? $clean : $bytes;
    }

    private function deleteStoredModelImage(string $imagePath): void
    {
        if (preg_match('#^' . self::MODEL_IMAGE_ROUTE . '/([a-f0-9]{32}\.jpg)$#', $imagePath, $matches) !== 1) {
            return;
        }
        $path = self::MODEL_IMAGE_DIR . '/' . $matches[1];
        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * @param list<string> $keys
     * @return list<int>
     */
    private function capabilityIdsForDeviceTypeAndKeys(string $deviceType, array $keys): array
    {
        $ids = [];
        foreach ($keys as $key) {
            $id = $this->db->genericCapabilities->findIdByDeviceTypeAndKey($deviceType, (string)$key);
            if ($id === null) {
                continue;
            }
            $ids[$id] = $id;
        }

        return array_values($ids);
    }

    /**
     * @return list<string>
     */
    private function requestableTelemetryKeys(string $protocol, string $supplier, string $deviceType): array
    {
        $catalogRequestable = [];
        foreach ($this->db->genericCapabilities->all($deviceType) as $capability) {
            if (!empty($capability['is_telemetry']) && !empty($capability['is_requestable'])) {
                $catalogRequestable[(string)$capability['capability_key']] = true;
            }
        }
        $supplierSupported = array_fill_keys(
            SupplierCapabilityTemplate::keysForSupplierDeviceType($supplier, $deviceType),
            true
        );

        $requestable = [];
        foreach (DeviceCommandCatalog::commandsForProtocol($protocol) as $command) {
            if ((string)($command['kind'] ?? '') !== 'request') {
                continue;
            }
            $feature = trim((string)($command['feature'] ?? ''));
            if (
                $feature !== ''
                && isset($catalogRequestable[$feature])
                && isset($supplierSupported[$feature])
            ) {
                $requestable[$feature] = true;
            }
        }

        return array_keys($requestable);
    }
}
