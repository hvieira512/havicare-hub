<?php

namespace Hub\Dashboard;

use Hub\Dashboard\Repository\DeviceConfigurationRepository;
use Hub\Dashboard\Repository\GenericCapabilityRepository;
use Hub\Dashboard\Repository\LicenseRepository;
use Hub\Dashboard\Repository\ApiUserRepository;
use Hub\Dashboard\Repository\CompanyRepository;
use Hub\Dashboard\Repository\ModelRepository;
use Hub\Dashboard\Repository\ModelCapabilityRepository;
use Hub\Dashboard\Repository\SupplierRepository;
use Hub\Dashboard\Repository\WhitelistRepository;

final class DashboardDataAccess
{
    public function __construct(
        public readonly SupplierRepository $suppliers,
        public readonly ModelRepository $models,
        public readonly ModelCapabilityRepository $modelCapabilities,
        public readonly GenericCapabilityRepository $genericCapabilities,
        public readonly WhitelistRepository $whitelist,
        public readonly DeviceConfigurationRepository $deviceConfigurations,
        public readonly ApiUserRepository $apiUsers,
        public readonly CompanyRepository $companies,
        public readonly LicenseRepository $licenses,
    ) {
    }

    public static function fromDatabase(DashboardDatabase $database): self
    {
        $pdo = $database->pdo();

        return new self(
            new SupplierRepository($pdo),
            new ModelRepository($pdo),
            new ModelCapabilityRepository($pdo),
            new GenericCapabilityRepository($pdo),
            new WhitelistRepository($pdo),
            new DeviceConfigurationRepository($pdo),
            new ApiUserRepository($pdo),
            new CompanyRepository($pdo),
            new LicenseRepository($pdo),
        );
    }
}
