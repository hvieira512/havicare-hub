<?php

namespace App\Services;

use App\Models\DeviceModel;
use App\Repositories\DeviceModelRepository;
use Illuminate\Database\Eloquent\Collection;

class DeviceModelService
{
    public function __construct(
        private readonly DeviceModelRepository $repository
    )
    {
    }

    public function all(): Collection
    {
        return $this->repository->all();
    }

    public function find(int $id): ?DeviceModel
    {
        return $this->repository->find($id);
    }

    public function create(int $supplierId, string $model, string $protocol, string $imagePath = ''): DeviceModel
    {
        return $this->repository->create($supplierId, $model, $protocol, $imagePath);
    }

    public function delete(DeviceModel $deviceModel): ?bool
    {
        return $this->repository->delete($deviceModel);
    }
}
