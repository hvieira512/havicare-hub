<?php

namespace Hub\Api\Services;

use Hub\Api\Http\ApiError;
use Hub\Api\Http\CollectionPresenter;
use Hub\Api\Http\LicenseColumns;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Api\Request\LicenseWriteRequest;
use Hub\Api\Request\RequestBinder;

class LicenseService
{
    private const DEFAULT_COLLECTION_LIMIT = 20;

    private CollectionPresenter $presenter;
    private RequestBinder $binder;

    public function __construct(private ApiDataAccess $db)
    {
        $this->presenter = new CollectionPresenter();
        $this->binder = new RequestBinder();
    }

    public function list(string $query = ''): array
    {
        $params = $this->presenter->params($query);
        // O `companyId` é o nome antigo do parâmetro, e é público: continua a valer ao lado
        // da coluna que o descritor agora anuncia.
        if (isset($params['companyId']) && !isset($params['company_id'])) {
            $params['company_id'] = $params['companyId'];
        }

        $companyIds = array_map(
            static fn (array $company): string => (string)($company['id'] ?? ''),
            $this->db->companies->all(),
        );

        return $this->presenter->present(
            $this->db->licenses->all(),
            LicenseColumns::definition(array_values($companyIds)),
            $params,
            self::DEFAULT_COLLECTION_LIMIT,
        );
    }

    /** O `licenseId` chega como texto tantas vezes como inteiro, e por isso converte-se. */
    public function create(array $payload): array
    {
        $request = $this->binder->bind(
            $payload,
            LicenseWriteRequest::class,
            [LicenseWriteRequest::GROUP_CREATE],
            coerceStrings: true,
        );
        if (is_array($request)) {
            return $request;
        }

        $refused = $this->refuseUnknownCompany($request->companyId);
        if ($refused !== null) {
            return $refused;
        }

        $id = $this->db->licenses->create(
            (int)$request->companyId,
            (int)$request->licenseId,
            trim($request->name ?? ''),
        );

        return ['status' => 'ok', 'id' => $id];
    }

    /** O que não vier no corpo fica como está: é o que o `?? $existing` fazia à mão. */
    public function update(int $id, array $payload): array
    {
        $existing = $this->db->licenses->findById($id);
        if ($existing === null) {
            return ApiError::licenseNotFound()->toArray();
        }

        $request = $this->binder->bind($payload, LicenseWriteRequest::class, coerceStrings: true);
        if (is_array($request)) {
            return $request;
        }

        $refused = $this->refuseUnknownCompany($request->companyId);
        if ($refused !== null) {
            return $refused;
        }

        $this->db->licenses->update(
            $id,
            $request->companyId ?? (int)$existing['company_id'],
            $request->licenseId ?? (int)$existing['license_id'],
            trim($request->name ?? (string)$existing['name']),
        );

        return ['status' => 'ok'];
    }

    /**
     * O `company_id` é chave estrangeira e resolve-se antes da escrita.
     *
     * @return array<string, mixed>|null
     */
    private function refuseUnknownCompany(?int $companyId): ?array
    {
        if ($companyId === null) {
            return null;
        }

        return $this->db->companies->findById($companyId) === null
            ? ApiError::companyNotFound()->toArray()
            : null;
    }

    public function delete(int $id): array
    {
        $existing = $this->db->licenses->findById($id);
        if ($existing === null) {
            return ApiError::licenseNotFound()->toArray();
        }
        $this->db->licenses->delete($id);

        return ['status' => 'ok'];
    }
}
