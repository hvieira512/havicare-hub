<?php

namespace Hub\Api\Routes;

use Hub\Api\Support\CollectionResponse;
use Hub\Dashboard\DashboardDataAccess;
use Hub\Dashboard\DeviceProtocol;
use Hub\Dashboard\GenericModelCapabilityCatalog;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

final class Models
{
    use CollectionResponse;

    private const MODEL_IMAGE_DIR = __DIR__ . '/../../../var/dashboard/model-images';
    private const MODEL_IMAGE_ROUTE = '/model-images';
    private const MAX_MODEL_IMAGE_BYTES = 5 * 1024 * 1024;
    private const MAX_MODEL_IMAGE_DIMENSION = 640;
    private const DEFAULT_COLLECTION_LIMIT = 20;

    public function __construct(private DashboardDataAccess $db)
    {
    }

    public function list(ServerRequestInterface $request): array
    {
        $baseUrl = $this->baseUrlFromRequest($request);
        $params = $this->queryParams((string)$request->getUri()->getQuery());
        $page = $this->queryPage($params);
        $limit = $this->queryLimit($params, self::DEFAULT_COLLECTION_LIMIT);
        $filters = [
            'supplier' => $this->queryFilter($params, 'supplier'),
            'protocol' => $this->queryFilter($params, 'protocol'),
            'deviceType' => $this->queryFilter($params, 'deviceType'),
            'model' => $this->queryFilter($params, 'model'),
        ];
        $models = array_values(array_filter($this->db->models->all(), static function (array $model) use ($filters): bool {
            $supplierRaw = trim((string)($model['supplier'] ?? ''));
            $supplier = mb_strtolower($supplierRaw);
            $protocol = mb_strtolower(DeviceProtocol::forSupplier($supplierRaw));
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
            'supplier' => $this->uniqueValues(array_map(
                static fn (array $model): string => trim((string)($model['supplier'] ?? '')),
                array_values(array_filter($this->db->models->all(), static function (array $model) use ($filters): bool {
                    $protocol = DeviceProtocol::forSupplier((string)($model['supplier'] ?? ''));
                    $deviceType = trim((string)($model['device_type'] ?? 'watch'));
                    return (($filters['protocol'] ?? null) === null || $protocol === $filters['protocol'])
                        && (($filters['deviceType'] ?? null) === null || $deviceType === $filters['deviceType']);
                }))
            )),
            'protocol' => $this->uniqueValues(array_map(
                static fn (array $model): string => DeviceProtocol::forSupplier((string)($model['supplier'] ?? '')),
                array_values(array_filter($this->db->models->all(), static function (array $model) use ($filters): bool {
                    $supplier = trim((string)($model['supplier'] ?? ''));
                    $deviceType = trim((string)($model['device_type'] ?? 'watch'));
                    return (($filters['supplier'] ?? null) === null || $supplier === $filters['supplier'])
                        && (($filters['deviceType'] ?? null) === null || $deviceType === $filters['deviceType']);
                }))
            )),
            'deviceType' => $this->uniqueValues(array_map(
                static fn (array $model): string => trim((string)($model['device_type'] ?? 'watch')),
                array_values(array_filter($this->db->models->all(), static function (array $model) use ($filters): bool {
                    $supplier = trim((string)($model['supplier'] ?? ''));
                    $protocol = DeviceProtocol::forSupplier((string)($model['supplier'] ?? ''));
                    return (($filters['supplier'] ?? null) === null || $supplier === $filters['supplier'])
                        && (($filters['protocol'] ?? null) === null || $protocol === $filters['protocol']);
                }))
            )),
            'model' => $this->uniqueValues(array_merge(
                array_map(static fn (array $m): string => trim((string)($m['internal_model'] ?? '')), $this->db->models->all()),
                array_map(static fn (array $m): string => trim((string)($m['commercial_name'] ?? '')), $this->db->models->all()),
            )),
        ];

        $catalog = $this->db->genericCapabilities->all();
        $enabledByModel = $this->db->modelCapabilities->enabledFeaturesForModelIds(array_map(
            static fn(array $model): int => (int)($model['id'] ?? 0),
            $models
        ));
        $models = array_map(function (array $model) use ($enabledByModel, $catalog, $baseUrl): array {
            $modelId = (int)($model['id'] ?? 0);
            $protocol = DeviceProtocol::forSupplier((string)($model['supplier'] ?? ''));
            return [
                'id' => $modelId,
                'supplier_id' => (int)($model['supplier_id'] ?? 0),
                'supplier' => (string)($model['supplier'] ?? ''),
                'internalModel' => (string)($model['internal_model'] ?? ''),
                'commercialName' => (string)($model['commercial_name'] ?? ''),
                'deviceType' => (string)($model['device_type'] ?? 'watch'),
                'protocol' => $protocol,
                'image' => $this->fullModelImage((string)($model['image'] ?? ''), $baseUrl),
                'capabilities' => GenericModelCapabilityCatalog::buildCapabilityMatrix($catalog, $enabledByModel[$modelId] ?? []),
            ];
        }, $models);

        return $this->collectionResponse($models, $page, $limit, $filters, $available);
    }

    public function show(int $id, ServerRequestInterface $request): array
    {
        $entry = $this->db->models->findById($id);
        if ($entry === null) {
            return ['error' => ['code' => 'model_not_found', 'message' => 'Model not found']];
        }

        $baseUrl = $this->baseUrlFromRequest($request);
        $protocol = DeviceProtocol::forSupplier((string)($entry['supplier_name'] ?? ''));
        $imagePath = (string)($entry['image_path'] ?? '');
        $catalog = $this->db->genericCapabilities->all();

        return [
            'id' => $id,
            'supplier' => (string)($entry['supplier_name'] ?? ''),
            'internalModel' => (string)($entry['internal_model'] ?? ''),
            'commercialName' => (string)($entry['commercial_name'] ?? ''),
            'deviceType' => (string)($entry['device_type'] ?? 'watch'),
            'protocol' => $protocol,
            'image' => $this->fullModelImage($imagePath, $baseUrl),
            'capabilities' => GenericModelCapabilityCatalog::buildCapabilityMatrix(
                $catalog,
                $this->db->modelCapabilities->enabledFeaturesForModelId($id)
            ),
        ];
    }

    public function create(ServerRequestInterface $request): array
    {
        $payload = $this->modelPayload($request);
        if (isset($payload['error'])) {
            return $payload;
        }
        $supplierId = (int)$payload['supplier_id'];
        $internalModel = (string)$payload['internal_model'];
        $commercialName = (string)$payload['commercial_name'];
        $deviceType = (string)$payload['device_type'];
        $enabledCapabilities = $payload['enabled_capabilities'];

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

        $payload = $this->modelPayload($request);
        if (isset($payload['error'])) {
            return $payload;
        }
        $supplierId = (int)$payload['supplier_id'];
        $internalModel = (string)$payload['internal_model'];
        $commercialName = (string)$payload['commercial_name'];
        $deviceType = (string)$payload['device_type'];
        $enabledCapabilities = $payload['enabled_capabilities'];
        if ($this->db->models->existsForDifferentId($id, $supplierId, $internalModel)) {
            return ['error' => ['code' => 'model_exists', 'message' => 'Another model with this supplier and model name already exists']];
        }

        $imagePath = $this->storeModelImage($request->getUploadedFiles()['image'] ?? null);
        if (is_array($imagePath)) {
            return $imagePath;
        }

        $this->db->models->update($id, $supplierId, $internalModel, $commercialName, $deviceType, $imagePath);
        $this->db->modelCapabilities->replaceForModelId($id, $enabledCapabilities);
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

    private function modelPayload(ServerRequestInterface $request): array
    {
        $decoded = $this->modelRequestData($request);
        if (!is_array($decoded)) {
            return ['error' => ['code' => 'invalid_request', 'message' => 'Invalid JSON']];
        }
        $supplierId = (int)($decoded['supplier_id'] ?? 0);
        $internalModel = trim((string)($decoded['internalModel'] ?? $decoded['internal_model'] ?? ''));
        $commercialName = trim((string)($decoded['commercialName'] ?? $decoded['commercial_name'] ?? ''));
        $deviceType = \Hub\Dashboard\DeviceMetadata::normalizeDeviceType((string)($decoded['deviceType'] ?? $decoded['device_type'] ?? 'watch'));
        if ($supplierId <= 0 || $internalModel === '' || $commercialName === '') {
            return ['error' => ['code' => 'invalid_request', 'message' => 'supplier_id, internalModel, and commercialName are required']];
        }
        $supplier = $this->db->suppliers->findById($supplierId);
        if ($supplier === null) {
            return ['error' => ['code' => 'supplier_not_found', 'message' => 'Supplier does not exist']];
        }

        $protocol = DeviceProtocol::forSupplier((string)$supplier['name']);
        if ($protocol === '') {
            return ['error' => ['code' => 'unknown_protocol', 'message' => 'Could not determine protocol for this supplier']];
        }

        $availableFeatures = GenericModelCapabilityCatalog::keysForProtocol($protocol);
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
            $enabledCapabilities = $availableFeatures;
        } else {
            $featureSet = array_flip($availableFeatures);
            $enabledCapabilities = array_values(array_filter($enabledCapabilities, static fn(string $f): bool => isset($featureSet[$f])));
        }

        return [
            'supplier_id' => $supplierId,
            'internal_model' => $internalModel,
            'commercial_name' => $commercialName,
            'device_type' => $deviceType,
            'enabled_capabilities' => $enabledCapabilities,
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

    private function baseUrlFromRequest(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $scheme = $uri->getScheme();
        $host = $uri->getHost();
        $port = $uri->getPort();
        $base = "$scheme://$host";
        if ($port !== null && $port !== 80 && $port !== 443) {
            $base .= ":$port";
        }
        return $base;
    }

    private function fullModelImage(string $path, string $baseUrl): ?string
    {
        if ($path === '') {
            return null;
        }
        return $baseUrl . $path;
    }
}
