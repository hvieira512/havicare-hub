<?php

namespace Hub\Api\Repository;

use Hub\Infrastructure\Persistence\DashboardDatabase;

final class ApiDataAccess
{
    public function __construct(
        public readonly SupplierRepository $suppliers,
        public readonly ModelRepository $models,
        public readonly ModelCapabilityRepository $modelCapabilities,
        public readonly SupplierDeviceTypeRepository $supplierDeviceTypes,
        public readonly GenericCapabilityRepository $genericCapabilities,
        public readonly WhitelistRepository $whitelist,
        public readonly DeviceConfigurationRepository $deviceConfigurations,
        public readonly DeviceConfigurationLifecycleRepository $configurationLifecycle,
        public readonly ApiUserRepository $apiUsers,
        public readonly CompanyRepository $companies,
        public readonly LicenseRepository $licenses,
        public readonly DashboardNotificationRepository $dashboardNotifications,
        public readonly GatewayDeviceLinkRepository $gatewayDeviceLinks,
    ) {
    }

    public static function fromDatabase(DashboardDatabase $database): self
    {
        $pdo = $database->pdo();

        return new self(
            new SupplierRepository($pdo),
            new ModelRepository($pdo),
            new ModelCapabilityRepository($pdo),
            new SupplierDeviceTypeRepository($pdo),
            new GenericCapabilityRepository($pdo),
            new WhitelistRepository($pdo),
            new DeviceConfigurationRepository($pdo),
            new DeviceConfigurationLifecycleRepository($pdo),
            new ApiUserRepository($pdo),
            new CompanyRepository($pdo),
            new LicenseRepository($pdo),
            new DashboardNotificationRepository($pdo),
            new GatewayDeviceLinkRepository($pdo),
        );
    }
}
