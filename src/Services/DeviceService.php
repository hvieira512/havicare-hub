<?php

namespace App\Services;

use App\Registry\DeviceCapabilities;
use App\Registry\Whitelist;
use App\Repository\DeviceRepository;
use App\Repository\ModelRepository;

class DeviceService
{
    private Whitelist $whitelist;
    private ?\PDO $pdo;
    private ?DeviceRepository $deviceRepo;
    private ?ModelRepository $modelRepo;
    /** @var callable(string):bool */
    private $onlineResolver;
    /** @var null|callable(string):void */
    private $deviceDataWarmup;

    public function __construct(
        Whitelist $whitelist,
        ?\PDO $pdo,
        ?DeviceRepository $deviceRepo,
        ?ModelRepository $modelRepo,
        ?callable $onlineResolver = null,
        ?callable $deviceDataWarmup = null,
    ) {
        $this->whitelist = $whitelist;
        $this->pdo = $pdo;
        $this->deviceRepo = $deviceRepo;
        $this->modelRepo = $modelRepo;
        $this->onlineResolver = $onlineResolver ?? static fn(string $imei): bool => false;
        $this->deviceDataWarmup = $deviceDataWarmup;
    }

    public function listDevices(array $params): array
    {
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
            foreach ($this->whitelist->all() as $imei => $info) {
                $devices[] = $this->deviceResource($imei, $info);
            }
            $total = count($devices);
        }

        return [
            'data' => $devices,
            'pagination' => $this->paginationResource($page, $limit, $total),
            'filters' => [
                'imei' => $params['imei'] ?? null,
                'model' => $params['model'] ?? null,
                'supplier' => $params['supplier'] ?? null,
                'enabled' => $params['enabled'] ?? null,
                'online' => $params['online'] ?? null,
            ],
        ];
    }

    public function getDevice(string $imei): array
    {
        $info = $this->whitelist->all()[$imei] ?? null;
        if ($info === null) {
            throw new ServiceException('device_not_found', 'Device not found', 404);
        }

        return ['data' => $this->deviceResource($imei, $info)];
    }

    public function createDevice(array $body): array
    {
        if ($this->pdo === null) {
            throw new ServiceException('mysql_unavailable', 'MySQL is not available', 503);
        }

        $imei = trim((string)($body['imei'] ?? ''));
        $model = trim((string)($body['model'] ?? ''));
        $enabled = (bool)($body['enabled'] ?? true);

        if ($imei === '' || $model === '') {
            throw new ServiceException('invalid_request', 'imei and model are required', 400);
        }

        $this->validateModelForDeviceAssignment($model);

        if ($this->whitelist->all()[$imei] ?? false) {
            throw new ServiceException('duplicate_device', 'Device already exists', 409);
        }

        $this->whitelist->register($imei, $model, $enabled);

        return [
            'status' => 201,
            'payload' => [
                'data' => $this->deviceResource($imei, ['model' => $model, 'enabled' => $enabled]),
            ],
        ];
    }

    public function updateDevice(string $imei, array $body): array
    {
        if ($this->pdo === null) {
            throw new ServiceException('mysql_unavailable', 'MySQL is not available', 503);
        }

        $all = $this->whitelist->all();
        if (!isset($all[$imei])) {
            throw new ServiceException('device_not_found', 'Device not found', 404);
        }

        $data = [];
        if (isset($body['model'])) {
            $model = trim((string)$body['model']);
            $this->validateModelForDeviceAssignment($model);
            $data['model'] = $model;
        }
        if (isset($body['enabled'])) {
            $data['enabled'] = (bool)$body['enabled'];
        }

        if ($data === []) {
            throw new ServiceException('no_data', 'No fields to update', 400);
        }

        $this->whitelist->update($imei, $data);

        return ['data' => $this->deviceResource($imei)];
    }

    public function deleteDevice(string $imei): array
    {
        if ($this->pdo === null) {
            throw new ServiceException('mysql_unavailable', 'MySQL is not available', 503);
        }

        $all = $this->whitelist->all();
        if (!isset($all[$imei])) {
            throw new ServiceException('device_not_found', 'Device not found', 404);
        }

        $info = $all[$imei];
        $this->whitelist->unregister($imei);

        return ['status' => 'deleted', 'data' => $this->deviceResource($imei, $info)];
    }

    public function deviceResource(string $imei, ?array $info = null): array
    {
        $info = $info ?? ($this->whitelist->all()[$imei] ?? []);

        $caps = DeviceCapabilities::forModel($info['model'] ?? '');
        if ($this->deviceDataWarmup !== null) {
            ($this->deviceDataWarmup)($imei);
        }

        return [
            'imei' => $imei,
            'model' => $info['model'] ?? null,
            'supplier' => $caps?->getSupplier(),
            'protocol' => $caps?->getProtocol(),
            'transport' => $caps?->getTransport(),
            'online' => ($this->onlineResolver)($imei),
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
                'online' => ($this->onlineResolver)($row['imei']),
                'enabled' => $row['enabled'] && $row['model_enabled'],
                'registeredAt' => $row['registered_at'],
            ];
        }

        return $devices;
    }

    private function validateModelForDeviceAssignment(string $model): void
    {
        $allModels = DeviceCapabilities::allModels();
        if (!in_array($model, $allModels, true)) {
            if ($this->modelRepo !== null) {
                $exists = $this->modelRepo->findByCode($model) !== null;
                if (!$exists) {
                    throw new ServiceException(
                        'invalid_model',
                        "Model '$model' not found. Available: " . implode(', ', $allModels),
                        400
                    );
                }
            }
        }
    }

    private function parsePage(mixed $value): int
    {
        $page = $value !== null ? (int)$value : 1;
        return max(1, $page);
    }

    private function parseLimit(mixed $value): int
    {
        $limit = $value !== null ? (int)$value : 50;
        return max(1, min(200, $limit));
    }

    private function parseNullableBool(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }

        $lower = strtolower((string)$value);
        if ($lower === '1' || $lower === 'true' || $lower === 'yes') {
            return true;
        }
        if ($lower === '0' || $lower === 'false' || $lower === 'no') {
            return false;
        }

        return null;
    }

    private function paginationResource(int $page, int $limit, int $total): array
    {
        return [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => $limit > 0 ? (int)ceil($total / $limit) : 1,
        ];
    }
}
