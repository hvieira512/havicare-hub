<?php

namespace Hub\Api\Services;

use Hub\Api\Http\ApiError;
use Hub\Api\Http\CollectionQuery;
use Hub\Api\Http\CollectionResponder;
use Hub\Api\Repository\ApiDataAccess;
use Hub\Api\Request\LicenseWriteRequest;
use Hub\Api\Request\RequestBinder;

class LicenseService
{
    private const DEFAULT_COLLECTION_LIMIT = 20;

    private CollectionQuery $query;
    private CollectionResponder $collection;
    private RequestBinder $binder;

    public function __construct(
        private ApiDataAccess $db,
        ?CollectionQuery $query = null,
        ?CollectionResponder $collection = null,
        ?RequestBinder $binder = null,
    ) {
        $this->query = $query ?? new CollectionQuery();
        $this->collection = $collection ?? new CollectionResponder();
        $this->binder = $binder ?? new RequestBinder();
    }

    public function list(string $query = ''): array
    {
        $params = $this->query->params($query);
        $page = $this->query->page($params);
        $limit = $this->query->limit($params, self::DEFAULT_COLLECTION_LIMIT);
        $companyId = $this->query->filter($params, 'companyId');
        $items = $companyId !== null
            ? $this->db->licenses->findByCompanyId((int)$companyId)
            : $this->db->licenses->all();
        $available = [
            'companyId' => $this->collection->uniqueValues(array_map(
                static fn (array $s): string => (string)($s['id'] ?? ''),
                $this->db->companies->all()
            )),
        ];

        return $this->collection->respond($items, $page, $limit, ['companyId' => $companyId], $available);
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

        $this->db->licenses->update(
            $id,
            $request->companyId ?? (int)$existing['company_id'],
            $request->licenseId ?? (int)$existing['license_id'],
            trim($request->name ?? (string)$existing['name']),
        );

        return ['status' => 'ok'];
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
