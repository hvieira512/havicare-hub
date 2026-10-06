<?php

declare(strict_types=1);

namespace Hub\Api\Services;

use Hub\Api\Http\ApiError;
use Hub\Api\Http\CollectionPresenter;
use Hub\Api\Http\CompanyColumns;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Api\Request\CompanyWriteRequest;
use Hub\Api\Request\RequestBinder;

class CompanyService
{
    private const DEFAULT_COLLECTION_LIMIT = 20;

    private CollectionPresenter $presenter;
    private RequestBinder $binder;

    public function __construct(private ApiDataAccess $db)
    {
        $this->presenter = new CollectionPresenter();
        $this->binder = new RequestBinder();
    }

    /** @return array<string, mixed> */
    public function list(string $query = ''): array
    {
        return $this->presenter->present(
            $this->db->companies->all(),
            CompanyColumns::definition(),
            $this->presenter->params($query),
            self::DEFAULT_COLLECTION_LIMIT,
        );
    }

    /**
     * O nome repetido responde 409, perguntado antes: o `create()` do repositório é idempotente e
     * devolve o id da linha que já existe.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        $request = $this->binder->bind($payload, CompanyWriteRequest::class);
        if (is_array($request)) {
            return $request;
        }

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
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $id, array $payload): array
    {
        $existing = $this->db->companies->findById($id);
        if ($existing === null) {
            return ApiError::companyNotFound()->toArray();
        }

        $request = $this->binder->bind($payload, CompanyWriteRequest::class);
        if (is_array($request)) {
            return $request;
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
