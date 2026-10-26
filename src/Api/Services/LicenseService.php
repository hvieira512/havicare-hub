<?php

declare(strict_types=1);

namespace Hub\Api\Services;

use Hub\Api\Http\ApiError;
use Hub\Api\Http\CollectionPresenter;
use Hub\Api\Http\CollectionColumns;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Api\Request\LicenseWriteRequest;
use Hub\Registry\Whitelist;

class LicenseService
{
    private CollectionPresenter $presenter;

    public function __construct(private ApiDataAccess $db, private ?Whitelist $whitelist = null)
    {
        $this->presenter = new CollectionPresenter();
    }

    /** @return array<string, mixed> */
    public function list(string $query = ''): array
    {
        // As empresas todas, também as que ainda não têm licenças.
        $companyIds = array_map(
            static fn (array $company): string => (string)($company['id'] ?? ''),
            $this->db->companies->all(),
        );

        return $this->presenter->present(
            array_map(self::present(...), $this->db->licenses->all()),
            new CollectionColumns(
                sortable: ['companyName', 'licenseId', 'name'],
                writable: LicenseWriteRequest::class,
                textFilters: ['name' => 'name', 'companyName' => 'companyName'],
                fixedOptions: ['companyId' => $companyIds],
                extra: ['id', 'radarCloudConfigured', 'deviceCount'],
            ),
            $query,
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'companyId' => (int)$row['company_id'],
            'companyName' => $row['company_name'] === null ? null : (string)$row['company_name'],
            'licenseId' => (int)$row['license_id'],
            'name' => (string)$row['name'],
            'radarCloudConfigured' => (bool)$row['radar_cloud_configured'],
            'deviceCount' => (int)$row['device_count'],
            'createdAt' => (string)$row['created_at'],
            'updatedAt' => (string)$row['updated_at'],
        ];
    }

    /**
     * O `licenseId` chega como texto tantas vezes como inteiro, e por isso converte-se.
     *
     * @return array<string, mixed>
     */
    public function create(LicenseWriteRequest $request): array
    {
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

    /**
     * O que não vier no corpo fica como está.
     *
     * @return array<string, mixed>
     */
    public function update(int $id, LicenseWriteRequest $request): array
    {
        $existing = $this->db->licenses->findById($id);
        if ($existing === null) {
            return ApiError::licenseNotFound()->toArray();
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
        $this->whitelist?->reload();

        return ['status' => 'ok'];
    }

    /**
     * O `companyId` é chave estrangeira e resolve-se antes da escrita.
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

    /** @return array<string, mixed> */
    public function delete(int $id): array
    {
        $existing = $this->db->licenses->findById($id);
        if ($existing === null) {
            return ApiError::licenseNotFound()->toArray();
        }
        $devices = $this->db->whitelist->countForLicense($id);
        if ($devices > 0) {
            return ApiError::licenseInUse($devices)->toArray();
        }
        $this->db->licenses->delete($id);

        return ['status' => 'ok'];
    }
}
