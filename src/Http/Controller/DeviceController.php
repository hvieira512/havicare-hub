<?php

namespace App\Http\Controller;

use React\Http\Message\Response;
use Psr\Http\Message\ServerRequestInterface;
use App\Registry\DeviceCapabilities;

class DeviceController extends Controller
{
    public function listDevices(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        $page = $this->parsePage($params['page'] ?? null);
        $limit = $this->parseLimit($params['limit'] ?? null);

        if ($this->pdo !== null && $this->deviceRepo !== null) {
            $filters = [
                'imei' => $params['imei'] ?? null,
                'model' => $params['model'] ?? null,
                'supplier' => $params['supplier'] ?? null,
                'enabled' => $this->parseNullableBool($params['enabled'] ?? null),
                'online' => $params['online'] ?? null,
            ];

            $rows = $this->deviceRepo->list($filters, $page, $limit);
            $total = $this->deviceRepo->countFiltered($filters);
            $devices = $this->deviceResourcesFromRows($rows);
        } else {
            $devices = [];
            foreach ($this->whitelist()->all() as $imei => $info) {
                $devices[] = $this->deviceResource($imei, $info);
            }
            $total = count($devices);
        }

        return $this->jsonResponse([
            'data' => $devices,
            'pagination' => $this->paginationResource($page, $limit, $total),
            'filters' => [
                'imei' => $params['imei'] ?? null,
                'model' => $params['model'] ?? null,
                'supplier' => $params['supplier'] ?? null,
                'enabled' => $params['enabled'] ?? null,
                'online' => $params['online'] ?? null,
            ],
        ]);
    }

    public function getDevice(string $imei): Response
    {
        $info = $this->whitelist()->all()[$imei] ?? null;
        if ($info === null) {
            return $this->errorResponse('device_not_found', 'Device not found', 404);
        }

        return $this->jsonResponse(['data' => $this->deviceResource($imei, $info)]);
    }

    public function createDevice(ServerRequestInterface $request): Response
    {
        if ($this->pdo === null) {
            return $this->errorResponse('mysql_unavailable', 'MySQL is not available', 503);
        }

        $body = json_decode((string)$request->getBody(), true);
        if (!is_array($body)) {
            return $this->errorResponse('invalid_request', 'Invalid JSON body', 400);
        }

        $imei = trim((string)($body['imei'] ?? ''));
        $model = trim((string)($body['model'] ?? ''));
        $enabled = (bool)($body['enabled'] ?? true);

        if ($imei === '' || $model === '') {
            return $this->errorResponse('invalid_request', 'imei and model are required', 400);
        }

        $validation = $this->validateModelForDeviceAssignment($model);
        if ($validation !== null) {
            return $validation;
        }

        if ($this->whitelist()->all()[$imei] ?? false) {
            return $this->errorResponse('duplicate_device', 'Device already exists', 409);
        }

        $this->whitelist()->register($imei, $model, $enabled);

        return $this->jsonResponse(
            ['data' => $this->deviceResource($imei, ['model' => $model, 'enabled' => $enabled])],
            201
        );
    }

    public function updateDevice(string $imei, ServerRequestInterface $request): Response
    {
        if ($this->pdo === null) {
            return $this->errorResponse('mysql_unavailable', 'MySQL is not available', 503);
        }

        $all = $this->whitelist()->all();
        if (!isset($all[$imei])) {
            return $this->errorResponse('device_not_found', 'Device not found', 404);
        }

        $body = json_decode((string)$request->getBody(), true);
        if (!is_array($body)) {
            return $this->errorResponse('invalid_request', 'Invalid JSON body', 400);
        }

        $data = [];
        if (isset($body['model'])) {
            $model = trim((string)$body['model']);
            $validation = $this->validateModelForDeviceAssignment($model);
            if ($validation !== null) {
                return $validation;
            }
            $data['model'] = $model;
        }
        if (isset($body['enabled'])) {
            $data['enabled'] = (bool)$body['enabled'];
        }

        if ($data === []) {
            return $this->errorResponse('no_data', 'No fields to update', 400);
        }

        $this->whitelist()->update($imei, $data);

        return $this->jsonResponse(['data' => $this->deviceResource($imei)]);
    }

    public function deleteDevice(string $imei): Response
    {
        if ($this->pdo === null) {
            return $this->errorResponse('mysql_unavailable', 'MySQL is not available', 503);
        }

        $all = $this->whitelist()->all();
        if (!isset($all[$imei])) {
            return $this->errorResponse('device_not_found', 'Device not found', 404);
        }

        $info = $all[$imei];
        $this->whitelist()->unregister($imei);

        return $this->jsonResponse(['status' => 'deleted', 'data' => $this->deviceResource($imei, $info)]);
    }

    private function deviceResource(string $imei, ?array $info = null): array
    {
        $info = $info ?? ($this->whitelist()->all()[$imei] ?? []);

        $caps = DeviceCapabilities::forModel($info['model'] ?? '');
        $this->deviceData($imei); // warm cache

        return [
            'imei' => $imei,
            'model' => $info['model'] ?? null,
            'supplier' => $caps?->getSupplier(),
            'protocol' => $caps?->getProtocol(),
            'transport' => $caps?->getTransport(),
            'online' => $this->deviceIsOnline($imei),
            'enabled' => $info['enabled'] ?? true,
            'registeredAt' => $info['registered_at'] ?? null,
        ];
    }

    private function deviceResourcesFromRows(array $rows): array
    {
        $devices = [];
        foreach ($rows as $row) {
            $devices[] = [
                'imei' => $row['imei'],
                'model' => $row['model_code'],
                'supplier' => $row['supplier_name'],
                'protocol' => $row['protocol'],
                'transport' => $row['transport'],
                'online' => $this->deviceIsOnline($row['imei']),
                'enabled' => $row['enabled'] && $row['model_enabled'],
                'registeredAt' => $row['registered_at'],
            ];
        }
        return $devices;
    }

    private function validateModelForDeviceAssignment(string $model): ?Response
    {
        $allModels = DeviceCapabilities::allModels();
        if (!in_array($model, $allModels, true)) {
            if ($this->modelRepo !== null) {
                $exists = $this->modelRepo->findByCode($model) !== null;
                if (!$exists) {
                    return $this->errorResponse('invalid_model', "Model '$model' not found. Available: " . implode(', ', $allModels), 400);
                }
            }
        }
        return null;
    }
}
