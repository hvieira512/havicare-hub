<?php

declare(strict_types=1);

namespace Hub\Api\Services;

use Hub\Api\Http\ApiError;
use Hub\Api\Http\CollectionPresenter;
use Hub\Api\Http\CollectionColumns;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Api\Request\CompanyWriteRequest;

class CompanyService
{
    private CollectionPresenter $presenter;

    public function __construct(private ApiDataAccess $db)
    {
        $this->presenter = new CollectionPresenter();
    }

    /** @return array<string, mixed> */
    public function list(string $query = ''): array
    {
        return $this->presenter->present(
            array_map(static fn (array $row): array => [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'licenseCount' => (int)$row['license_count'],
                'createdAt' => (string)$row['created_at'],
                'updatedAt' => (string)$row['updated_at'],
            ], $this->db->companies->all()),
            new CollectionColumns(
                sortable: ['name', 'licenseCount'],
                writable: CompanyWriteRequest::class,
                textFilters: ['name' => 'name'],
                extra: ['id'],
            ),
            $query,
        );
    }

    /**
     * O nome repetido responde 409, perguntado antes: o `create()` do repositório é idempotente e
     * devolve o id da linha que já existe.
     *
     * @return array<string, mixed>
     */
    public function create(CompanyWriteRequest $request): array
    {
        $name = $request->normalizedName();
        if ($this->db->companies->findByName($name) !== null) {
            return ApiError::duplicateCompany()->toArray();
        }

        return ['status' => 'ok', 'id' => $this->db->companies->create($name)];
    }

    /**
     * Renomear para o nome de outra empresa é 409, verificado antes de o `UNIQUE` da base o
     * recusar com uma excepção.
     *
     * @return array<string, mixed>
     */
    public function update(int $id, CompanyWriteRequest $request): array
    {
        $existing = $this->db->companies->findById($id);
        if ($existing === null) {
            return ApiError::companyNotFound()->toArray();
        }

        $name = $request->normalizedName();
        $taken = $this->db->companies->findByName($name);
        if ($taken !== null && (int)$taken['id'] !== $id) {
            return ApiError::duplicateCompany()->toArray();
        }

        $this->db->companies->update($id, $name);

        return ['status' => 'ok'];
    }

    /** @return array<string, mixed> */
    public function delete(int $id): array
    {
        $existing = $this->db->companies->findById($id);
        if ($existing === null) {
            return ApiError::companyNotFound()->toArray();
        }
        $this->db->companies->delete($id);

        return ['status' => 'ok'];
    }
}
