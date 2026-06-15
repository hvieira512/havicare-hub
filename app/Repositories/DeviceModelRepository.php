<?php

namespace App\Repositories;

use App\Models\DeviceModel;
use Illuminate\Database\Eloquent\Collection;

class DeviceModelRepository
{
    public function all(): Collection
    {
        return DeviceModel::with('supplier')->orderBy('model')->get();
    }

    public function find(int $id): ?DeviceModel
    {
        return DeviceModel::with('supplier')->find($id);
    }

    public function create(int $supplierId, string $model, string $protocol, string $imagePath = ''): DeviceModel
    {
        return DeviceModel::create([
            'supplier_id' => $supplierId,
            'model' => $model,
            'protocol' => $protocol,
            'image_path' => $imagePath,
        ]);
    }

    public function delete(DeviceModel $deviceModel): ?bool
    {
        return $deviceModel->delete();
    }

    public function findBySupplierAndModel(int $supplierId, string $model): ?DeviceModel
    {
        return DeviceModel::where('supplier_id', $supplierId)
            ->where('model', $model)
            ->first();
    }
}
