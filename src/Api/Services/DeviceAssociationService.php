<?php

declare(strict_types=1);

namespace Hub\Api\Services;

use Hub\Api\Auth\ApiAuthContext;
use Hub\Api\Http\ApiError;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Api\Request\DeviceAssociationRequest;
use Hub\Api\Request\RequestBinder;
use Hub\State\DeviceRegistry;
use Hub\Domain\DeviceMetadata;
use Hub\Device\DeviceHubServer;
use Hub\Registry\Whitelist;

final class DeviceAssociationService
{
    private RequestBinder $binder;

    public function __construct(
        private DeviceRegistry $store,
        private Whitelist $whitelist,
        private ApiDataAccess $db,
        private ?DeviceHubServer $hub = null,
        ?RequestBinder $binder = null,
    ) {
        $this->binder = $binder ?? new RequestBinder();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function associate(string $imei, array $payload, ?ApiAuthContext $auth = null): array
    {
        $existing = $this->whitelist->getMetadata($imei);
        if ($existing === null) {
            return ApiError::deviceNotFound()->toArray();
        }

        // O `coerceStrings` porque os clientes mandam o `licenseId` como texto.
        $request = $this->binder->bind($payload, DeviceAssociationRequest::class, coerceStrings: true);
        if (is_array($request)) {
            return $request;
        }

        // A normalização fica fora da constraint: o `normalizeCompany()` transforma o vazio em
        // `'null'`, e o `NotBlank` deixaria de ver o vazio que tem de recusar.
        $company = DeviceMetadata::normalizeCompany($request->company);
        $licenseId = $request->licenseId;

        if ($auth !== null && !$auth->isAdmin()) {
            if (!$auth->canAccessTenant($company, $licenseId)) {
                return ApiError::forbidden()->toArray();
            }
            if ($existing->licenseId !== 0 || $existing->company !== 'null') {
                return ApiError::deviceAlreadyAssociated()->toArray();
            }
        }

        $mayProvisionLicense = $auth === null || $auth->isAdmin();
        if ($this->license($company, $licenseId, $mayProvisionLicense) === null) {
            return ApiError::invalidAssociation()->toArray();
        }

        $this->writeAssociation($existing, $imei, $company, $licenseId);

        return ['status' => 'ok', 'imei' => $imei, 'association' => ['company' => $company, 'licenseId' => $licenseId]];
    }

    /** @return array<string, mixed> */
    public function remove(string $imei, ?ApiAuthContext $auth = null): array
    {
        $existing = $this->whitelist->getMetadata($imei);
        if ($existing === null) {
            return ApiError::deviceNotFound()->toArray();
        }

        $licenseId = $existing->licenseId;
        $company = $existing->company;
        if ($licenseId === 0 && $company === 'null') {
            return ApiError::associationNotFound()->toArray();
        }
        if ($auth !== null && !$auth->isAdmin() && !$auth->canAccessTenant($company, $licenseId)) {
            return ApiError::deviceNotFound()->toArray();
        }

        $this->writeAssociation($existing, $imei, 'null', 0);

        return ['status' => 'ok', 'imei' => $imei, 'association' => ['company' => 'null', 'licenseId' => 0]];
    }

    /**
     * Muda o dono no inventário e no Redis, o Redis primeiro: é uma projecção reconstruída do
     * MySQL, e a ordem inversa deixaria o dispositivo com o estado retido do cliente anterior.
     */
    private function writeAssociation(DeviceMetadata $existing, string $imei, string $company, int $licenseId): void
    {
        $this->releaseRetainedStatus($existing, $imei, $company, $licenseId);
        $this->store->updateDeviceAssociation($imei, $company, $licenseId);
        $this->whitelist->updateAssociation($imei, $company, $licenseId);
    }

    /** @return array<string, mixed>|null */
    private function license(string $company, int $licenseId, bool $createIfMissing): ?array
    {
        $companyRow = $this->db->companies->findByName($company);
        if ($companyRow === null) {
            return null;
        }

        $license = $this->db->licenses->findByCompanyAndLicense((int)$companyRow['id'], $licenseId);
        if ($license !== null || !$createIfMissing) {
            return $license;
        }

        $createdId = $this->db->licenses->create((int)$companyRow['id'], $licenseId, '');
        return $this->db->licenses->findById($createdId);
    }

    /**
     * Larga o estado retido no tópico do cliente anterior, que continuaria a servir o último
     * estado do dispositivo.
     */
    private function releaseRetainedStatus(DeviceMetadata $existing, string $imei, string $company, int $licenseId): void
    {
        if ($existing->company === DeviceMetadata::normalizeCompany($company) && $existing->licenseId === $licenseId) {
            return;
        }

        $this->hub?->clearRetainedStatus($existing->company, $existing->licenseId, $existing->deviceType, $imei);
    }
}
