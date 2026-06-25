<?php

namespace Hub\Api\Routes;

use Hub\Api\Support\CollectionResponse;
use Hub\Command\DeviceConfigurationCatalog;
use Hub\Dashboard\DashboardDataAccess;
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

    public function list(string $query = ''): array
    {
        $params = $this->queryParams($query);
        $page = $this->queryPage($params);
        $limit = $this->queryLimit($params, self::DEFAULT_COLLECTION_LIMIT);
        $filters = [
            'supplier' => $this->queryFilter($params, 'supplier', 'all'),
            'protocol' => $this->queryFilter($params, 'protocol', 'all'),
            'deviceType' => $this->queryFilter($params, 'deviceType', 'all'),
        ];
        $models = array_values(array_filter($this->db->models->all(), static function (array $model) use ($filters): bool {
            $supplier = trim((string)($model['supplier'] ?? ''));
            $protocol = trim((string)($model['protocol'] ?? ''));
            $deviceType = trim((string)($model['device_type'] ?? 'watch'));

            return (($filters['supplier'] ?? 'all') === 'all' || $supplier === $filters['supplier'])
                && (($filters['protocol'] ?? 'all') === 'all' || $protocol === $filters['protocol'])
                && (($filters['deviceType'] ?? 'all') === 'all' || $deviceType === $filters['deviceType']);
        }));
        $available = [
            'supplier' => $this->uniqueValues(array_map(
                static fn (array $model): string => trim((string)($model['supplier'] ?? '')),
                array_values(array_filter($this->db->models->all(), static function (array $model) use ($filters): bool {
                    $protocol = trim((string)($model['protocol'] ?? ''));
                    $deviceType = trim((string)($model['device_type'] ?? 'watch'));
                    return (($filters['protocol'] ?? 'all') === 'all' || $protocol === $filters['protocol'])
                        && (($filters['deviceType'] ?? 'all') === 'all' || $deviceType === $filters['deviceType']);
                }))
            )),
            'protocol' => $this->uniqueValues(array_map(
                static fn (array $model): string => trim((string)($model['protocol'] ?? '')),
                array_values(array_filter($this->db->models->all(), static function (array $model) use ($filters): bool {
                    $supplier = trim((string)($model['supplier'] ?? ''));
                    $deviceType = trim((string)($model['device_type'] ?? 'watch'));
                    return (($filters['supplier'] ?? 'all') === 'all' || $supplier === $filters['supplier'])
                        && (($filters['deviceType'] ?? 'all') === 'all' || $deviceType === $filters['deviceType']);
                }))
            )),
            'deviceType' => $this->uniqueValues(array_map(
                static fn (array $model): string => trim((string)($model['device_type'] ?? 'watch')),
                array_values(array_filter($this->db->models->all(), static function (array $model) use ($filters): bool {
                    $supplier = trim((string)($model['supplier'] ?? ''));
                    $protocol = trim((string)($model['protocol'] ?? ''));
                    return (($filters['supplier'] ?? 'all') === 'all' || $supplier === $filters['supplier'])
                        && (($filters['protocol'] ?? 'all') === 'all' || $protocol === $filters['protocol']);
                }))
            )),
        ];

        $enabledByModel = $this->db->modelRequestCapabilities->enabledCommandsForModelIds(array_map(
            static fn(array $model): int => (int)($model['id'] ?? 0),
            $models
        ));
        $models = array_map(function (array $model) use ($enabledByModel): array {
            $modelId = (int)($model['id'] ?? 0);
            $protocol = (string)($model['protocol'] ?? '');
            return [
                'id' => $modelId,
                'supplier_id' => (int)($model['supplier_id'] ?? 0),
                'supplier' => (string)($model['supplier'] ?? ''),
                'internalModel' => (string)($model['internal_model'] ?? ''),
                'commercialName' => (string)($model['commercial_name'] ?? ''),
                'deviceType' => (string)($model['device_type'] ?? 'watch'),
                'protocol' => $protocol,
                'image' => (string)($model['image'] ?? ''),
                'availableRequests' => $this->requestCatalogForProtocol($protocol),
                'enabledRequests' => $enabledByModel[$modelId] ?? [],
            ];
        }, $models);

        return $this->collectionResponse($models, $page, $limit, $filters, $available);
    }

    public function show(int $id): array
    {
        $entry = $this->db->models->findById($id);
        if ($entry === null) {
            return ['error' => ['code' => 'model_not_found', 'message' => 'Model not found']];
        }

        $protocol = (string)($entry['protocol'] ?? '');
        $imagePath = (string)($entry['image_path'] ?? '');

        return [
            'id' => $id,
            'supplier' => (string)($entry['supplier_name'] ?? ''),
            'internalModel' => (string)($entry['internal_model'] ?? ''),
            'commercialName' => (string)($entry['commercial_name'] ?? ''),
            'deviceType' => (string)($entry['device_type'] ?? 'watch'),
            'protocol' => $protocol,
            'image' => $imagePath !== '' ? $imagePath : null,
            'configurationCatalog' => DeviceConfigurationCatalog::configsForProtocol($protocol),
            'availableRequests' => $this->requestCatalogForProtocol($protocol),
            'enabledRequests' => $this->db->modelRequestCapabilities->enabledCommandsForModelId($id),
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
        $protocol = (string)$payload['protocol'];
        $enabledRequests = $payload['enabled_requests'];

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
        $this->db->models->add($supplierId, $internalModel, $commercialName, $deviceType, $protocol, $imagePath);
        $supplier = $this->db->suppliers->findById($supplierId);
        $stored = is_array($supplier) ? $this->db->models->find((string)$supplier['name'], $internalModel) : null;
        if (is_array($stored)) {
            $this->db->modelRequestCapabilities->replaceForModelId((int)$stored['id'], $enabledRequests);
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
        $protocol = (string)$payload['protocol'];
        $enabledRequests = $payload['enabled_requests'];
        if ($this->db->models->existsForDifferentId($id, $supplierId, $internalModel)) {
            return ['error' => ['code' => 'model_exists', 'message' => 'Another model with this supplier and model name already exists']];
        }

        $imagePath = $this->storeModelImage($request->getUploadedFiles()['image'] ?? null);
        if (is_array($imagePath)) {
            return $imagePath;
        }

        $this->db->models->update($id, $supplierId, $internalModel, $commercialName, $deviceType, $protocol, $imagePath);
        $this->db->modelRequestCapabilities->replaceForModelId($id, $enabledRequests);
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

        $protocol = trim((string)($decoded['protocol'] ?? ''));
        if ($protocol === '') {
            $protocol = $this->protocolForSupplier((string)$supplier['name']);
            if ($protocol === '') {
                return ['error' => ['code' => 'unknown_protocol', 'message' => 'Could not determine protocol for this supplier']];
            }
        }

        $availableRequests = $this->requestCatalogForProtocol($protocol);
        $availableCommandIds = [];
        $requestAliases = [];
        foreach ($availableRequests as $entry) {
            $requestId = (string)($entry['id'] ?? $entry['command'] ?? '');
            $nativeCommand = (string)($entry['command'] ?? '');
            if ($requestId === '') {
                continue;
            }
            $availableCommandIds[] = $requestId;
            $requestAliases[$requestId] = $requestId;
            if ($nativeCommand !== '') {
                $requestAliases[$nativeCommand] = $requestId;
            }
        }
        $hasEnabledRequestsSelection = array_key_exists('enabledRequestsConfigured', $decoded)
            || array_key_exists('enabledRequests', $decoded)
            || array_key_exists('enabledRequests[]', $decoded);
        $enabledRequests = $this->requestValues($decoded['enabledRequests'] ?? $decoded['enabledRequests[]'] ?? null);
        if (!$hasEnabledRequestsSelection) {
            $enabledRequests = $availableCommandIds;
        } else {
            $normalized = [];
            foreach ($enabledRequests as $command) {
                $requestId = $requestAliases[$command] ?? null;
                if ($requestId !== null) {
                    $normalized[$requestId] = true;
                }
            }
            $enabledRequests = array_keys($normalized);
        }

        return [
            'supplier_id' => $supplierId,
            'internal_model' => $internalModel,
            'commercial_name' => $commercialName,
            'device_type' => $deviceType,
            'protocol' => $protocol,
            'enabled_requests' => $enabledRequests,
        ];
    }

    private function protocolForSupplier(string $supplierName): string
    {
        return match ($supplierName) {
            'Wonlex' => 'wonlex-json',
            'Vivistar' => 'vivistar-iw',
            '4P Touch' => 'four-p-touch',
            'Voerka' => 'voerka-ncs',
            'Qinglanst' => 'qinglanst',
            default => '',
        };
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
     * @return list<array<string, mixed>>
     */
    private function requestCatalogForProtocol(string $protocol): array
    {
        return array_values(array_filter(
            \Hub\Command\DeviceCommandCatalog::commandsForProtocol($protocol),
            static fn(array $entry): bool => (string)($entry['kind'] ?? '') === 'request'
        ));
    }

    /**
     * @return list<string>
     */
    private function requestValues(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $item) {
            $command = trim((string)$item);
            if ($command === '') {
                continue;
            }
            $normalized[$command] = true;
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
}
