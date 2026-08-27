<?php

declare(strict_types=1);

namespace Hub\Api\Services;

use Hub\Api\Auth\ApiAuthContext;
use Hub\Api\Repository\ApiDataAccess;
use Hub\Dashboard\DashboardStoreContract;
use Hub\Domain\DeviceMetadata;
use Hub\DeviceHubServer;
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

    public function associate(string $imei, string $body, ?ApiAuthContext $auth = null): array
    {
        $existing = $this->whitelist->getMetadata($imei);
        if ($existing === null) {
            return ['error' => ['code' => 'not_found', 'message' => 'Device was not found']];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['error' => ['code' => 'invalid_request', 'message' => 'Invalid JSON']];
        }

        $company = DeviceMetadata::normalizeCompany((string)($decoded['company'] ?? ''));
        $licenseId = DeviceMetadata::normalizeLicenseId((string)($decoded['licenseId'] ?? ''));
        if ($company === '' || $licenseId === 0) {
            return ['error' => ['code' => 'invalid_request', 'message' => 'company and licenseId are required']];
        }

        if ($auth !== null && !$auth->isAdmin()) {
            if (!$auth->canAccessTenant($company, $licenseId)) {
                return ['error' => ['code' => 'forbidden', 'message' => 'Forbidden']];
            }
            $currentLicenseId = DeviceMetadata::normalizeLicenseId((string)($existing['licenseId'] ?? '0'));
            $currentCompany = trim((string)($existing['company'] ?? 'null'));
            if ($currentLicenseId !== 0 || $currentCompany !== 'null') {
                return ['error' => ['code' => 'device_already_associated', 'message' => 'Device is already associated']];
            }
        }

        $mayProvisionLicense = $auth === null || $auth->isAdmin();
        if ($this->license($company, $licenseId, $mayProvisionLicense) === null) {
            return ['error' => ['code' => 'invalid_association', 'message' => 'company and licenseId do not match a registered license']];
        }

        $this->releaseRetainedStatus($existing, $imei, $company, $licenseId);
        $this->whitelist->updateAssociation($imei, $company, $licenseId);
        $this->store->updateDeviceAssociation($imei, $company, $licenseId);

        return ['status' => 'ok', 'imei' => $imei, 'association' => ['company' => $company, 'licenseId' => $licenseId]];
    }

    public function remove(string $imei, ?ApiAuthContext $auth = null): array
    {
        $existing = $this->whitelist->getMetadata($imei);
        if ($existing === null) {
            return ['error' => ['code' => 'not_found', 'message' => 'Device was not found']];
        }

        $licenseId = DeviceMetadata::normalizeLicenseId((string)($existing['licenseId'] ?? '0'));
        $company = trim((string)($existing['company'] ?? 'null'));
        if ($licenseId === 0 && $company === 'null') {
            return ['error' => ['code' => 'association_not_found', 'message' => 'Device association was not found']];
        }
        if ($auth !== null && !$auth->isAdmin() && !$auth->canAccessTenant($company, $licenseId)) {
            return ['error' => ['code' => 'not_found', 'message' => 'Device was not found']];
        }

        $this->releaseRetainedStatus($existing, $imei, 'null', 0);
        $this->whitelist->updateAssociation($imei, 'null', 0);
        $this->store->updateDeviceAssociation($imei, 'null', 0);

        return ['status' => 'ok', 'imei' => $imei, 'association' => ['company' => 'null', 'licenseId' => 0]];
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
