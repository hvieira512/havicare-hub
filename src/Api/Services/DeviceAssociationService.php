<?php

declare(strict_types=1);

namespace Hub\Api\Services;

use Hub\Api\Auth\ApiAuthContext;
use Hub\Api\Http\ApiError;
use Hub\Api\Repository\ApiDataAccess;
use Hub\Dashboard\DashboardStoreContract;
use Hub\Domain\DeviceMetadata;
use Hub\Device\DeviceHubServer;
use Hub\Registry\Whitelist;

final class DeviceAssociationService
{
    public function __construct(
        private DashboardStoreContract $store,
        private Whitelist $whitelist,
        private ApiDataAccess $db,
        private ?DeviceHubServer $hub = null,
    ) {
    }

    public function associate(string $imei, array $payload, ?ApiAuthContext $auth = null): array
    {
        $existing = $this->whitelist->getMetadata($imei);
        if ($existing === null) {
            return ApiError::deviceNotFound()->toArray();
        }

        $company = DeviceMetadata::normalizeCompany((string)($payload['company'] ?? ''));
        $licenseId = DeviceMetadata::normalizeLicenseId((string)($payload['licenseId'] ?? ''));
        if ($company === '' || $licenseId === 0) {
            return ApiError::invalidRequest('company and licenseId are required')->toArray();
        }

        if ($auth !== null && !$auth->isAdmin()) {
            if (!$auth->canAccessTenant($company, $licenseId)) {
                return ApiError::forbidden()->toArray();
            }
            $currentLicenseId = DeviceMetadata::normalizeLicenseId((string)($existing['licenseId'] ?? '0'));
            $currentCompany = trim((string)($existing['company'] ?? 'null'));
            if ($currentLicenseId !== 0 || $currentCompany !== 'null') {
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

    public function remove(string $imei, ?ApiAuthContext $auth = null): array
    {
        $existing = $this->whitelist->getMetadata($imei);
        if ($existing === null) {
            return ApiError::deviceNotFound()->toArray();
        }

        $licenseId = DeviceMetadata::normalizeLicenseId((string)($existing['licenseId'] ?? '0'));
        $company = trim((string)($existing['company'] ?? 'null'));
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
     * Muda o dono do dispositivo no inventário e no Redis.
     *
     * O Redis vai primeiro de propósito: é uma projecção do inventário e é reconstruído a
     * partir dele, portanto um par novo escrito lá antes do SQL falhar não estraga nada --
     * a listagem lê-se do MySQL. Ao contrário, o SQL escrito primeiro e o Redis a falhar
     * deixava o dispositivo a servir o estado retido do cliente anterior.
     *
     * @param array<string, mixed> $existing metadata before the change
     */
    private function writeAssociation(array $existing, string $imei, string $company, int $licenseId): void
    {
        $this->releaseRetainedStatus($existing, $imei, $company, $licenseId);
        $this->store->updateDeviceAssociation($imei, $company, $licenseId);
        $this->whitelist->updateAssociation($imei, $company, $licenseId);
    }

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
     * Larga o estado retido que um dispositivo deixa atrás de si no cliente anterior.
     *
     * Sem isto, o tópico antigo continua a servir o último estado do dispositivo a quem
     * subscreve esse cliente, muito depois de ele ter mudado.
     *
     * @param array<string, mixed> $existing metadata before the change
     */
    private function releaseRetainedStatus(array $existing, string $imei, string $company, int $licenseId): void
    {
        $previousCompany = DeviceMetadata::normalizeCompany((string)($existing['company'] ?? 'null'));
        $previousLicenseId = DeviceMetadata::normalizeLicenseId($existing['licenseId'] ?? 0);
        if ($previousCompany === DeviceMetadata::normalizeCompany($company) && $previousLicenseId === $licenseId) {
            return;
        }

        $this->hub?->clearRetainedStatus(
            $previousCompany,
            $previousLicenseId,
            DeviceMetadata::normalizeDeviceType((string)($existing['deviceType'] ?? 'watch')),
            $imei
        );
    }
}
