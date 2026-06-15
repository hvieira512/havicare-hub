<?php

namespace App\Repositories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Collection;

class SupplierRepository
{
    public function all(): Collection
    {
        return Supplier::withCount('models')->orderBy('name')->get();
    }

    public function find(int $id): ?Supplier
    {
        return Supplier::withCount('models')->find($id);
    }

    public function create(string $name, bool $enabled = true): Supplier
    {
        return Supplier::create(['name' => $name, 'enabled' => $enabled]);
    }

    public function update(Supplier $supplier, array $data): bool
    {
        return $supplier->update($data);
    }

    public function delete(Supplier $supplier): ?bool
    {
        return $supplier->delete();
    }

    public function countModels(int $id): int
    {
        return Supplier::findOrFail($id)->models()->count();
    }
}
