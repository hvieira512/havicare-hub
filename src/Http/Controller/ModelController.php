<?php

namespace App\Http\Controller;

use React\Http\Message\Response;
use Psr\Http\Message\ServerRequestInterface;
use App\Registry\DeviceCapabilities;

class ModelController extends Controller
{
    public function listModels(ServerRequestInterface $request): Response
    {
        if ($this->pdo === null || $this->modelRepo === null) {
            return $this->errorResponse('mysql_unavailable', 'MySQL is not available', 503);
        }

        $params = $request->getQueryParams();
        $page = $this->parsePage($params['page'] ?? null);
        $limit = $this->parseLimit($params['limit'] ?? null);

        $filters = [
            'code' => $params['code'] ?? null,
            'name' => $params['name'] ?? null,
            'supplierId' => $params['supplierId'] ?? null,
            'supplierName' => $params['supplierName'] ?? null,
            'protocol' => $params['protocol'] ?? null,
            'transport' => $params['transport'] ?? null,
            'enabled' => $this->parseNullableBool($params['enabled'] ?? null),
        ];

        $models = array_map(fn(array $row): array => $this->modelResource($row), $this->modelRepo->list($filters, $page, $limit));
        $total = $this->modelRepo->countFiltered($filters);

        return $this->jsonResponse([
            'data' => $models,
            'pagination' => $this->paginationResource($page, $limit, $total),
            'filters' => [
                'code' => $params['code'] ?? null,
                'name' => $params['name'] ?? null,
                'supplierId' => $params['supplierId'] ?? null,
                'supplierName' => $params['supplierName'] ?? null,
                'protocol' => $params['protocol'] ?? null,
                'transport' => $params['transport'] ?? null,
                'enabled' => $params['enabled'] ?? null,
            ],
        ]);
    }

    public function getModel(string $code): Response
    {
        if ($this->pdo === null || $this->modelRepo === null) {
            return $this->errorResponse('mysql_unavailable', 'MySQL is not available', 503);
        }

        $row = $this->modelRepo->findByCode($code);
        if ($row === null) {
            return $this->errorResponse('model_not_found', 'Model not found', 404);
        }

        return $this->jsonResponse(['data' => $this->modelResource($row)]);
    }

    public function createModel(ServerRequestInterface $request): Response
    {
        if ($this->pdo === null || $this->modelRepo === null || $this->supplierRepo === null) {
            return $this->errorResponse('mysql_unavailable', 'MySQL is not available', 503);
        }

        $body = json_decode((string)$request->getBody(), true);
        if (!is_array($body)) {
            return $this->errorResponse('invalid_request', 'Invalid JSON body', 400);
        }

        $code = trim((string)($body['code'] ?? ''));
        $name = trim((string)($body['name'] ?? ''));
        $supplierId = (int)($body['supplierId'] ?? 0);
        $protocol = trim((string)($body['protocol'] ?? ''));
        $transport = trim((string)($body['transport'] ?? ''));

        if ($code === '' || $name === '' || $protocol === '' || $transport === '' || $supplierId === 0) {
            return $this->errorResponse('invalid_request', 'code, name, protocol, transport, and supplierId are required', 400);
        }

        if (!preg_match('/^[a-zA-Z0-9_-]{2,64}$/', $code)) {
            return $this->errorResponse('invalid_request', 'Model code must be 2-64 chars: letters, digits, hyphens, underscores', 400);
        }

        if ($this->modelRepo->existsCode($code)) {
            return $this->errorResponse('duplicate_model', 'Model code already exists', 409);
        }

        $supplier = $this->supplierRepo->find($supplierId);
        if ($supplier === null) {
            return $this->errorResponse('invalid_supplier', "Supplier #$supplierId not found", 400);
        }

        if (!$this->isSupportedProtocol($protocol)) {
            return $this->errorResponse('invalid_protocol', "Unsupported protocol '$protocol'", 400, [
                'supported' => $this->supportedProtocols(),
            ]);
        }

        $data = $this->normalizeModelPayload($body, true);
        $this->modelRepo->insert($data);
        $row = $this->modelRepo->findByCode($code);

        DeviceCapabilities::setDatabasePdo($this->pdo);

        return $this->jsonResponse(['data' => $this->modelResource($row)], 201);
    }

    public function updateModel(string $code, ServerRequestInterface $request): Response
    {
        if ($this->pdo === null || $this->modelRepo === null || $this->supplierRepo === null) {
            return $this->errorResponse('mysql_unavailable', 'MySQL is not available', 503);
        }

        $existing = $this->modelRepo->findByCode($code);
        if ($existing === null) {
            return $this->errorResponse('model_not_found', 'Model not found', 404);
        }

        $body = json_decode((string)$request->getBody(), true);
        if (!is_array($body)) {
            return $this->errorResponse('invalid_request', 'Invalid JSON body', 400);
        }

        if (isset($body['supplierId'])) {
            $sid = (int)$body['supplierId'];
            if ($this->supplierRepo->find($sid) === null) {
                return $this->errorResponse('invalid_supplier', "Supplier #$sid not found", 400);
            }
        }

        if (isset($body['protocol']) && !$this->isSupportedProtocol($body['protocol'])) {
            return $this->errorResponse('invalid_protocol', "Unsupported protocol '{$body['protocol']}'", 400, [
                'supported' => $this->supportedProtocols(),
            ]);
        }

        $data = $this->normalizeModelPayload($body, false, $existing);
        if ($data === []) {
            return $this->errorResponse('no_data', 'No fields to update', 400);
        }

        $this->modelRepo->updateByCode($code, $data);
        $row = $this->modelRepo->findByCode($code);

        DeviceCapabilities::setDatabasePdo($this->pdo);

        return $this->jsonResponse(['data' => $this->modelResource($row)]);
    }

    public function deleteModel(string $code): Response
    {
        if ($this->pdo === null || $this->modelRepo === null) {
            return $this->errorResponse('mysql_unavailable', 'MySQL is not available', 503);
        }

        $existing = $this->modelRepo->findByCode($code);
        if ($existing === null) {
            return $this->errorResponse('model_not_found', 'Model not found', 404);
        }

        $deviceCount = $this->modelRepo->countDevicesUsingModelCode($code);
        if ($deviceCount > 0) {
            return $this->errorResponse('model_in_use', "Model is used by $deviceCount device(s). Remove or reassign them first.", 409);
        }

        $this->modelRepo->deleteByCode($code);

        return $this->jsonResponse(['status' => 'deleted', 'data' => $this->modelResource($existing)]);
    }

    public function listFeatureMappings(string $code): Response
    {
        if ($this->pdo === null || $this->modelRepo === null) {
            return $this->errorResponse('mysql_unavailable', 'MySQL is not available', 503);
        }
        $model = $this->modelRepo->findByCode($code);
        if ($model === null) {
            return $this->errorResponse('model_not_found', 'Model not found', 404);
        }

        return $this->jsonResponse([
            'data' => [
                'model' => $model['code'],
                'mappings' => $this->modelRepo->listFeatureMappingsByCode($code),
            ],
        ]);
    }

    public function replaceFeatureMappings(string $code, ServerRequestInterface $request): Response
    {
        if ($this->pdo === null || $this->modelRepo === null) {
            return $this->errorResponse('mysql_unavailable', 'MySQL is not available', 503);
        }
        if ($this->modelRepo->findByCode($code) === null) {
            return $this->errorResponse('model_not_found', 'Model not found', 404);
        }

        $body = json_decode((string)$request->getBody(), true);
        if (!is_array($body)) {
            return $this->errorResponse('invalid_request', 'Invalid JSON body', 400);
        }
        $rows = $body['mappings'] ?? null;
        if (!is_array($rows)) {
            return $this->errorResponse('invalid_request', 'mappings must be an array', 400);
        }

        try {
            $normalized = array_map(fn(mixed $row): array => $this->normalizeFeatureMappingPayload($row), $rows);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse('invalid_request', $e->getMessage(), 400);
        }

        $this->modelRepo->replaceFeatureMappingsByCode($code, $normalized);
        DeviceCapabilities::setDatabasePdo($this->pdo);

        return $this->jsonResponse([
            'data' => [
                'model' => $code,
                'mappings' => $this->modelRepo->listFeatureMappingsByCode($code),
            ],
        ]);
    }

    public function upsertFeatureMapping(string $code, ServerRequestInterface $request): Response
    {
        if ($this->pdo === null || $this->modelRepo === null) {
            return $this->errorResponse('mysql_unavailable', 'MySQL is not available', 503);
        }
        if ($this->modelRepo->findByCode($code) === null) {
            return $this->errorResponse('model_not_found', 'Model not found', 404);
        }

        $body = json_decode((string)$request->getBody(), true);
        if (!is_array($body)) {
            return $this->errorResponse('invalid_request', 'Invalid JSON body', 400);
        }

        try {
            $mapping = $this->normalizeFeatureMappingPayload($body);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse('invalid_request', $e->getMessage(), 400);
        }

        $this->modelRepo->upsertFeatureMappingByCode($code, $mapping);
        DeviceCapabilities::setDatabasePdo($this->pdo);

        return $this->jsonResponse([
            'status' => 'saved',
            'data' => [
                'model' => $code,
                'mapping' => $mapping,
                'mappings' => $this->modelRepo->listFeatureMappingsByCode($code),
            ],
        ]);
    }

    public function deleteFeatureMapping(string $code, string $nativeType): Response
    {
        if ($this->pdo === null || $this->modelRepo === null) {
            return $this->errorResponse('mysql_unavailable', 'MySQL is not available', 503);
        }
        if ($this->modelRepo->findByCode($code) === null) {
            return $this->errorResponse('model_not_found', 'Model not found', 404);
        }

        $deleted = $this->modelRepo->deleteFeatureMappingByCode($code, trim($nativeType));
        if (!$deleted) {
            return $this->errorResponse('mapping_not_found', 'Mapping not found for this model/nativeType', 404);
        }
        DeviceCapabilities::setDatabasePdo($this->pdo);

        return $this->jsonResponse([
            'status' => 'deleted',
            'data' => [
                'model' => $code,
                'nativeType' => $nativeType,
            ],
        ]);
    }

    private function modelResource(array $row): array
    {
        $caps = DeviceCapabilities::forModel($row['code']);

        return [
            'id' => $row['id'],
            'supplierId' => $row['supplier_id'],
            'supplierName' => $row['supplier_name'],
            'code' => $row['code'],
            'name' => $row['name'],
            'protocol' => $row['protocol'],
            'transport' => $row['transport'],
            'enabled' => $row['enabled'],
            'passive' => $row['passive'],
            'active' => $row['active'],
            'features' => $row['features'] ?? $caps?->getFeatures() ?? [],
            'nativeMappings' => $row['native_mappings'] ?? [],
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
        ];
    }

    private function normalizeModelPayload(array $body, bool $create, ?array $existing = null): array
    {
        $data = [];

        $supplierId = (int)($body['supplierId'] ?? 0);
        if ($create || isset($body['supplierId'])) {
            $data['supplier_id'] = $supplierId;
        }

        if ($create || isset($body['code'])) {
            $data['code'] = trim((string)($body['code'] ?? ''));
        }
        if ($create || isset($body['name'])) {
            $data['name'] = trim((string)($body['name'] ?? ''));
        }
        if ($create || isset($body['protocol'])) {
            $data['protocol'] = trim((string)($body['protocol'] ?? ''));
        }
        if ($create || isset($body['transport'])) {
            $data['transport'] = trim((string)($body['transport'] ?? ''));
        }
        if (isset($body['enabled'])) {
            if (!is_bool($body['enabled'])) {
                throw new \InvalidArgumentException('enabled must be a boolean');
            }
            $data['enabled'] = $body['enabled'];
        }

        return $data;
    }

    private function normalizeFeaturesObject(array $features): array
    {
        $normalized = [];

        foreach ($features as $feature => $commands) {
            if (!is_array($commands)) {
                continue;
            }

            $normalized[$feature] = [
                'passive' => array_values($commands['passive'] ?? []),
                'active' => array_values($commands['active'] ?? []),
            ];
        }

        ksort($normalized);
        return $normalized;
    }

    private function validateFeatureCommands(array $passiveOrActive, array $otherList, array $features): void
    {
        if ($features === []) {
            return;
        }

        $allFeatureCommands = [];
        foreach ($features as $feature => $commands) {
            $allFeatureCommands = array_merge(
                $allFeatureCommands,
                $commands['passive'] ?? [],
                $commands['active'] ?? []
            );
        }

        foreach ($passiveOrActive as $cmd) {
            $inFeatures = in_array($cmd, $allFeatureCommands, true);
            $inOther = in_array($cmd, $otherList, true);

            if ($features !== [] && !$inFeatures && !$inOther) {
                throw new \InvalidArgumentException("Command '$cmd' must be declared in features or as the opposite direction");
            }
        }
    }

    private function normalizeFeatureMappingPayload(mixed $row): array
    {
        if (!is_array($row)) {
            throw new \InvalidArgumentException('Each mapping must be an object');
        }

        $nativeType = trim((string)($row['nativeType'] ?? ''));
        if ($nativeType === '') {
            throw new \InvalidArgumentException('nativeType is required');
        }

        $feature = isset($row['feature']) ? trim((string)$row['feature']) : '';
        if ($feature === '') {
            $feature = null;
        }

        if (isset($row['isActive']) && !is_bool($row['isActive'])) {
            throw new \InvalidArgumentException('isActive must be a boolean');
        }
        if (isset($row['enabled']) && !is_bool($row['enabled'])) {
            throw new \InvalidArgumentException('enabled must be a boolean');
        }

        $description = null;
        if (isset($row['description'])) {
            $description = trim((string)$row['description']);
            if ($description === '') {
                $description = null;
            }
        }

        return [
            'nativeType' => $nativeType,
            'feature' => $feature,
            'isActive' => (bool)($row['isActive'] ?? false),
            'description' => $description,
            'enabled' => (bool)($row['enabled'] ?? true),
        ];
    }
}
