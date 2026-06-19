<?php

namespace Hub\Dashboard;

use Hub\Dashboard\Repository\DeviceConfigurationRepository;
use Hub\Dashboard\Repository\HistoryRepository;
use Hub\Dashboard\Repository\ModelRepository;
use Hub\Dashboard\Repository\ModelRequestCapabilityRepository;
use Hub\Dashboard\Repository\SupplierRepository;
use Hub\Dashboard\Repository\WhitelistRepository;

final class DashboardDataAccess
{
    public function __construct(
        public readonly SupplierRepository $suppliers,
        public readonly ModelRepository $models,
        public readonly ModelRequestCapabilityRepository $modelRequestCapabilities,
        public readonly WhitelistRepository $whitelist,
        public readonly HistoryRepository $history,
        public readonly DeviceConfigurationRepository $deviceConfigurations,
    ) {
    }

    public static function fromDatabase(DashboardDatabase $database): self
    {
        $pdo = $database->pdo();

        return new self(
            new SupplierRepository($pdo),
            new ModelRepository($pdo),
            new ModelRequestCapabilityRepository($pdo),
            new WhitelistRepository($pdo),
            new HistoryRepository($pdo),
            new DeviceConfigurationRepository($pdo),
        );
    }
}
