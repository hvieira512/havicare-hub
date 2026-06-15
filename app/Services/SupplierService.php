<?php

namespace App\Services;

use App\Models\Supplier;
use App\Repositories\SupplierRepository;
use Illuminate\Database\Eloquent\Collection;

class SupplierService
{
    public function __construct(
        private readonly SupplierRepository $repository
    )
    {
    }

    public function all(): Collection
    {
        return $this->repository->all();
    }

    public function find(int $id): ?Supplier
    {
        return $this->repository->find($id);
    }

    public function create(string $name): Supplier
    {
        return $this->repository->create($name);
    }

    public function update(Supplier $supplier, array $data): bool
    {
        return $this->repository->update($supplier, $data);
    }

    public function delete(Supplier $supplier): ?bool
    {
        return $this->repository->delete($supplier);
    }
}
