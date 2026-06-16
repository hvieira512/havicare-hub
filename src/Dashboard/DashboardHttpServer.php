<?php

namespace Hub\Dashboard;

use Hub\Command\DeviceCommandCatalog;
use Hub\Command\DeviceConfigurationCatalog;
use Hub\Http\OpenApiSpec;
use Hub\DeviceHubServer;
use Hub\PendingDownlinkQueue;
use Hub\Registry\Whitelist;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use React\Http\Message\Response;

final class DashboardHttpServer
{
    private const MODEL_IMAGE_DIR = __DIR__ . '/../../var/dashboard/model-images';
    private const MODEL_IMAGE_ROUTE = '/model-images';
    private const MAX_MODEL_IMAGE_BYTES = 5 * 1024 * 1024;
    private const MAX_MODEL_IMAGE_DIMENSION = 640;

    public function __construct(
        private DashboardStore $store,
        private Whitelist $whitelist,
        private DeviceHubServer $hub,
        private ?PendingDownlinkQueue $downlinkQueue,
        private DatabaseStore $db,
        private string $username,
        private string $password,
    ) {
        foreach ($this->whitelist->all() as $imei => $metadata) {
            $this->store->registerDevice((string)$imei, (string)($metadata['supplier'] ?? ''), (string)($metadata['model'] ?? ''));
        }
    }

    public function __invoke(ServerRequestInterface $request): Response
    {
        if (!$this->isAuthorized($request)) {
            return new Response(401, ['WWW-Authenticate' => 'Basic realm="Devices Hub"', 'Content-Type' => 'text/plain'], 'Unauthorized');
        }

        $path = $request->getUri()->getPath();
        $method = strtoupper($request->getMethod());

        try {
            if ($method === 'GET' && ($path === '/' || $path === '/dashboard')) {
                return $this->html($this->page());
            }
            if ($method === 'GET' && $path === '/api/dashboard/summary') {
                return $this->json($this->summary());
            }
            if ($method === 'GET' && preg_match('#^/api/devices/([^/]+)$#', $path, $matches) === 1) {
                return $this->json($this->device(rawurldecode($matches[1])));
            }
            if ($method === 'GET' && preg_match('#^/api/devices/([^/]+)/configuration$#', $path, $matches) === 1) {
                return $this->json($this->deviceConfiguration(rawurldecode($matches[1])));
            }
            if ($method === 'PUT' && preg_match('#^/api/devices/([^/]+)/configuration$#', $path, $matches) === 1) {
                return $this->json($this->saveDeviceConfiguration(rawurldecode($matches[1]), (string)$request->getBody()));
            }
            if ($method === 'POST' && preg_match('#^/api/devices/([^/]+)/configuration/([^/]+)/apply$#', $path, $matches) === 1) {
                return $this->json($this->applyDeviceConfiguration(rawurldecode($matches[1]), rawurldecode($matches[2])));
            }
            if ($method === 'POST' && preg_match('#^/api/devices/([^/]+)/commands$#', $path, $matches) === 1) {
                return $this->json($this->command(rawurldecode($matches[1]), (string)$request->getBody()));
            }
            if ($method === 'POST' && $path === '/api/devices') {
                return $this->json($this->addDevice((string)$request->getBody()));
            }
            if ($method === 'PUT' && preg_match('#^/api/devices/([^/]+)$#', $path, $matches) === 1) {
                return $this->json($this->updateDevice(rawurldecode($matches[1]), (string)$request->getBody()));
            }
            if ($method === 'DELETE' && preg_match('#^/api/devices/([^/]+)$#', $path, $matches) === 1) {
                return $this->json($this->deleteDevice(rawurldecode($matches[1])));
            }
            if ($method === 'GET' && $path === '/api/models') {
                return $this->json($this->modelsList());
            }
            if ($method === 'POST' && $path === '/api/models') {
                return $this->json($this->addModel($request));
            }
            if ($method === 'PUT' && preg_match('#^/api/models/(\d+)$#', $path, $matches) === 1) {
                return $this->json($this->updateModel((int)$matches[1], $request));
            }
            if ($method === 'DELETE' && preg_match('#^/api/models/(\d+)$#', $path, $matches) === 1) {
                return $this->json($this->deleteModel((int)$matches[1]));
            }
            if ($method === 'GET' && $path === '/api/suppliers') {
                return $this->json($this->suppliersList());
            }
            if ($method === 'POST' && $path === '/api/suppliers') {
                return $this->json($this->addSupplier((string)$request->getBody()));
            }
            if ($method === 'PUT' && preg_match('#^/api/suppliers/(\d+)$#', $path, $matches) === 1) {
                return $this->json($this->updateSupplier((int)$matches[1], (string)$request->getBody()));
            }
            if ($method === 'DELETE' && preg_match('#^/api/suppliers/(\d+)$#', $path, $matches) === 1) {
                return $this->json($this->deleteSupplier((int)$matches[1]));
            }
            if ($method === 'GET' && $path === '/api/openapi.json') {
                return $this->json(OpenApiSpec::get());
            }
            if ($method === 'GET' && $path === '/api/docs') {
                return $this->html($this->swaggerUi());
            }
            if ($method === 'GET' && preg_match('#^' . self::MODEL_IMAGE_ROUTE . '/([a-f0-9]{32}\.jpg)$#', $path, $matches) === 1) {
                return $this->modelImage($matches[1]);
            }
            if ($method === 'GET' && !str_starts_with($path, '/api/') && $path !== '/' && $path !== '/dashboard') {
                $file = __DIR__ . $path;
                if (file_exists($file) && is_file($file)) {
                    return $this->staticFile($file);
                }
            }
        } catch (\Throwable $e) {
            return $this->json(['error' => ['code' => 'server_error', 'message' => $e->getMessage()]], 500);
        }

        return $this->json(['error' => ['code' => 'not_found', 'message' => 'Not found']], 404);
    }

    private function isAuthorized(ServerRequestInterface $request): bool
    {
        if ($this->username === '' || $this->password === '') {
            return true;
        }

        $header = $request->getHeaderLine('Authorization');
        if (!str_starts_with($header, 'Basic ')) {
            return false;
        }

        $decoded = base64_decode(substr($header, 6), true);
        if (!is_string($decoded) || !str_contains($decoded, ':')) {
            return false;
        }

        [$username, $password] = explode(':', $decoded, 2);
        return hash_equals($this->username, $username) && hash_equals($this->password, $password);
    }

    private function summary(): array
    {
        $devices = $this->store->devices();
        $online = count(array_filter($devices, static fn(array $device): bool => (bool)($device['online'] ?? false)));
        $waiting = 0;
        $failed = 0;
        foreach ($devices as $device) {
            foreach ($this->store->commands((string)($device['imei'] ?? '')) as $command) {
                $status = (string)($command['status'] ?? '');
                $waiting += $status === 'waiting' ? 1 : 0;
                $failed += in_array($status, ['failed', 'dropped'], true) ? 1 : 0;
            }
        }

        return [
            'models' => $this->db->models(),
            'devices' => $devices,
            'counts' => [
                'online' => $online,
                'offline' => max(0, count($devices) - $online),
                'waiting' => $waiting,
                'failed' => $failed,
            ],
        ];
    }

    private function device(string $imei): array
    {
        $device = $this->store->device($imei);
        $protocol = (string)($device['protocol'] ?? $this->protocolForModel((string)($device['supplier'] ?? ''), (string)($device['model'] ?? '')));

        return [
            'device' => $device,
            'commands' => DeviceCommandCatalog::commandsForProtocol($protocol),
            'configuration' => [
                'supported' => count(DeviceConfigurationCatalog::configsForProtocol($protocol)),
                'stored' => count($this->db->configurations($imei)),
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

    private function command(string $imei, string $body): array
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
        $entry = DeviceCommandCatalog::commandForProtocol($protocol, $command);
        if ($entry === null) {
            return ['error' => ['code' => 'unsupported_command', 'message' => 'Command is not supported for this device']];
        }

        $bytes = DeviceCommandCatalog::buildDownlink($protocol, $imei, $command);
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

    private function deviceConfiguration(string $imei): array
    {
        $device = $this->store->device($imei);
        $metadata = $this->whitelist->getMetadata($imei) ?? [];
        $supplier = (string)($device['supplier'] ?? ($metadata['supplier'] ?? ''));
        $model = (string)($device['model'] ?? ($metadata['model'] ?? ''));
        $protocol = (string)($device['protocol'] ?? $this->protocolForModel($supplier, $model));

        return [
            'device' => array_merge($device, ['imei' => $imei, 'supplier' => $supplier, 'model' => $model, 'protocol' => $protocol]),
            'catalog' => DeviceConfigurationCatalog::configsForProtocol($protocol),
            'configurations' => $this->db->configurations($imei),
        ];
    }

    private function saveDeviceConfiguration(string $imei, string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['configs']) || !is_array($decoded['configs'])) {
            return ['error' => ['code' => 'invalid_request', 'message' => 'configs object is required']];
        }

        $results = [];
        foreach ($decoded['configs'] as $key => $payload) {
            if (!is_string($key) || !is_array($payload)) {
                return ['error' => ['code' => 'invalid_config', 'message' => 'Each config entry must be an object']];
            }
            $result = $this->persistAndApplyConfiguration($imei, $key, $payload);
            if (isset($result['error'])) {
                return $result;
            }
            $results[] = $result;
        }

        return ['status' => 'ok', 'results' => $results, 'configuration' => $this->deviceConfiguration($imei)];
    }

    private function applyDeviceConfiguration(string $imei, string $key): array
    {
        foreach ($this->db->configurations($imei) as $row) {
            if (($row['config_key'] ?? '') === $key) {
                return $this->persistAndApplyConfiguration($imei, $key, $row['desired_payload'] ?? []);
            }
        }

        return ['error' => ['code' => 'config_not_found', 'message' => 'Desired configuration was not found']];
    }

    private function persistAndApplyConfiguration(string $imei, string $key, array $payload): array
    {
        $device = $this->store->device($imei);
        $metadata = $this->whitelist->getMetadata($imei) ?? [];
        $supplier = (string)($device['supplier'] ?? ($metadata['supplier'] ?? ''));
        $model = (string)($device['model'] ?? ($metadata['model'] ?? ''));
        $protocol = (string)($device['protocol'] ?? $this->protocolForModel($supplier, $model));
        if ($protocol === '') {
            return ['error' => ['code' => 'unknown_protocol', 'message' => 'Device protocol could not be resolved']];
        }

        $error = DeviceConfigurationCatalog::validate($protocol, $key, $payload);
        if ($error !== null) {
            return ['error' => ['code' => 'invalid_config', 'message' => $error]];
        }

        $commandPayload = DeviceConfigurationCatalog::commandPayload($protocol, $key, $payload);
        $command = $commandPayload['command'];
        $bytes = DeviceCommandCatalog::buildDownlink($protocol, $imei, $command, $commandPayload['payload']);
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
        $this->db->saveDesiredConfiguration($imei, $key, $protocol, $supplier, $model, $command, $payload, (string)$record['status'], $id);

        return ['status' => $record['status'], 'key' => $key, 'command' => $command, 'id' => $id];
    }

    private function protocolForModel(string $supplier, string $model): string
    {
        $protocol = $this->db->protocolForModel($supplier, $model);
        if ($protocol !== '') {
            return $protocol;
        }

        foreach (DeviceCommandCatalog::models() as $entry) {
            if (strcasecmp((string)$entry['supplier'], $supplier) === 0 && strcasecmp((string)$entry['model'], $model) === 0) {
                return (string)$entry['protocol'];
            }
        }

        return '';
    }

    private function addDevice(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['error' => ['code' => 'invalid_request', 'message' => 'Invalid JSON']];
        }
        $imei = trim((string)($decoded['imei'] ?? ''));
        $supplier = trim((string)($decoded['supplier'] ?? ''));
        $model = trim((string)($decoded['model'] ?? ''));
        if ($imei === '' || $supplier === '' || $model === '') {
            return ['error' => ['code' => 'invalid_request', 'message' => 'imei, supplier, and model are required']];
        }
        $this->whitelist->register($imei, $supplier, $model);
        $this->store->registerDevice($imei, $supplier, $model);
        return ['status' => 'ok', 'imei' => $imei];
    }

    private function updateDevice(string $imei, string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['error' => ['code' => 'invalid_request', 'message' => 'Invalid JSON']];
        }
        $newImei = trim((string)($decoded['imei'] ?? $imei));
        $supplier = trim((string)($decoded['supplier'] ?? ''));
        $model = trim((string)($decoded['model'] ?? ''));
        if ($newImei === '' || $supplier === '' || $model === '') {
            return ['error' => ['code' => 'invalid_request', 'message' => 'imei, supplier, and model are required']];
        }
        if ($newImei !== $imei) {
            $this->whitelist->unregister($imei);
        }
        $this->whitelist->register($newImei, $supplier, $model);
        $this->store->registerDevice($newImei, $supplier, $model);
        return ['status' => 'ok', 'imei' => $newImei];
    }

    private function deleteDevice(string $imei): array
    {
        $this->whitelist->unregister($imei);
        $this->store->deleteDevice($imei);
        return ['status' => 'ok', 'imei' => $imei];
    }

    private function json(array $payload, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function html(string $body): Response
    {
        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }

    private function page(): string
    {
        ob_start();
        require __DIR__ . '/index.php';
        return (string) ob_get_clean();
    }

    private function swaggerUi(): string
    {
        return '<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>API Docs</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css">
</head>
<body>
  <div id="swagger-ui"></div>
  <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
  <script>
    SwaggerUIBundle({url: "/api/openapi.json", dom_id: "#swagger-ui"});
  </script>
</body>
</html>';
    }

    private function modelsList(): array
    {
        return ['models' => $this->db->models()];
    }

    private function addModel(ServerRequestInterface $request): array
    {
        $payload = $this->modelPayload($request);
        if (isset($payload['error'])) {
            return $payload;
        }
        $supplierId = (int)$payload['supplier_id'];
        $model = (string)$payload['model'];
        $protocol = (string)$payload['protocol'];

        $imagePath = $this->storeModelImage($request->getUploadedFiles()['image'] ?? null);
        if (is_array($imagePath)) {
            return $imagePath;
        }
        $supplier = $this->db->supplierFindById($supplierId);
        $previousImagePath = null;
        if (is_string($imagePath) && is_array($supplier)) {
            $existing = $this->db->findModel((string)$supplier['name'], $model);
            $previousImagePath = is_array($existing) ? (string)($existing['image_path'] ?? '') : null;
        }
        $this->db->addModel($supplierId, $model, $protocol, $imagePath);
        if (is_string($imagePath) && $previousImagePath !== null && $previousImagePath !== $imagePath) {
            $this->deleteStoredModelImage($previousImagePath);
        }
        return ['status' => 'ok'];
    }

    private function updateModel(int $id, ServerRequestInterface $request): array
    {
        $current = $this->db->findModelById($id);
        if ($current === null) {
            return ['error' => ['code' => 'model_not_found', 'message' => 'Model not found']];
        }

        $payload = $this->modelPayload($request);
        if (isset($payload['error'])) {
            return $payload;
        }
        $supplierId = (int)$payload['supplier_id'];
        $model = (string)$payload['model'];
        $protocol = (string)$payload['protocol'];
        if ($this->db->modelExistsForDifferentId($id, $supplierId, $model)) {
            return ['error' => ['code' => 'model_exists', 'message' => 'Another model with this supplier and model name already exists']];
        }

        $imagePath = $this->storeModelImage($request->getUploadedFiles()['image'] ?? null);
        if (is_array($imagePath)) {
            return $imagePath;
        }

        $this->db->updateModel($id, $supplierId, $model, $protocol, $imagePath);
        if (is_string($imagePath)) {
            $this->deleteStoredModelImage((string)($current['image_path'] ?? ''));
        }

        return ['status' => 'ok'];
    }

    private function modelPayload(ServerRequestInterface $request): array
    {
        $decoded = $this->modelRequestData($request);
        if (!is_array($decoded)) {
            return ['error' => ['code' => 'invalid_request', 'message' => 'Invalid JSON']];
        }
        $supplierId = (int)($decoded['supplier_id'] ?? 0);
        $model = trim((string)($decoded['model'] ?? ''));
        $protocol = trim((string)($decoded['protocol'] ?? ''));
        if ($supplierId <= 0 || $model === '' || $protocol === '') {
            return ['error' => ['code' => 'invalid_request', 'message' => 'supplier_id, model, and protocol are required']];
        }
        if ($this->db->supplierFindById($supplierId) === null) {
            return ['error' => ['code' => 'supplier_not_found', 'message' => 'Supplier does not exist']];
        }

        return ['supplier_id' => $supplierId, 'model' => $model, 'protocol' => $protocol];
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
     * @return string|array<string, array<string, string>>|null
     */
    private function storeModelImage(mixed $upload): string|array|null
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

    private function deleteModel(int $id): array
    {
        $model = $this->db->findModelById($id);
        $this->db->deleteModel($id);
        if (is_array($model)) {
            $this->deleteStoredModelImage((string)($model['image_path'] ?? ''));
        }
        return ['status' => 'ok'];
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

    private function suppliersList(): array
    {
        return ['suppliers' => $this->db->supplierList()];
    }

    private function addSupplier(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['error' => ['code' => 'invalid_request', 'message' => 'Invalid JSON']];
        }
        $name = trim((string)($decoded['name'] ?? ''));
        if ($name === '') {
            return ['error' => ['code' => 'invalid_request', 'message' => 'name is required']];
        }
        $id = $this->db->supplierCreate($name);
        return ['status' => 'ok', 'id' => $id];
    }

    private function updateSupplier(int $id, string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['error' => ['code' => 'invalid_request', 'message' => 'Invalid JSON']];
        }
        $newName = isset($decoded['name']) ? trim((string)$decoded['name']) : null;
        $enabled = array_key_exists('enabled', $decoded) ? (bool)$decoded['enabled'] : null;
        if ($newName !== null && $newName === '') {
            return ['error' => ['code' => 'invalid_request', 'message' => 'name cannot be empty']];
        }
        if ($this->db->supplierFindById($id) === null) {
            return ['error' => ['code' => 'supplier_not_found', 'message' => 'Supplier not found']];
        }
        if ($newName !== null) {
            $this->db->supplierRename($id, $newName);
        }
        if ($enabled !== null) {
            $this->db->supplierUpdate($id, $enabled);
        }
        return ['status' => 'ok'];
    }

    private function deleteSupplier(int $id): array
    {
        $supplier = $this->db->supplierFindById($id);
        if ($supplier === null) {
            return ['error' => ['code' => 'supplier_not_found', 'message' => 'Supplier not found']];
        }
        $count = $this->db->supplierCountModels($id);
        if ($count > 0) {
            return ['error' => ['code' => 'supplier_in_use', 'message' => "Cannot delete supplier '{$supplier['name']}': {$count} model(s) reference it"]];
        }
        $this->db->supplierDelete($id);
        return ['status' => 'ok'];
    }

    private function staticFile(string $path): Response
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'js' => 'application/javascript',
            'css' => 'text/css',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            default => 'text/plain',
        };
        return new Response(200, ['Content-Type' => $mime], (string) file_get_contents($path));
    }

    private function modelImage(string $filename): Response
    {
        $path = self::MODEL_IMAGE_DIR . '/' . $filename;
        if (!is_file($path)) {
            return $this->json(['error' => ['code' => 'not_found', 'message' => 'Not found']], 404);
        }
        return new Response(200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'public, max-age=31536000, immutable'], (string)file_get_contents($path));
    }
}
