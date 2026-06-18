<?php

namespace Hub\Dashboard;

use App\Repositories\DashboardDeviceConfigurationRepository;
use App\Repositories\DashboardHistoryRepository;
use App\Repositories\DashboardModelRepository;
use App\Repositories\DashboardSupplierRepository;
use App\Repositories\DashboardWhitelistRepository;

final class DashboardDataAccess
{
    public function __construct(
        public readonly DashboardSupplierRepository $suppliers,
        public readonly DashboardModelRepository $models,
        public readonly DashboardWhitelistRepository $whitelist,
        public readonly DashboardHistoryRepository $history,
        public readonly DashboardDeviceConfigurationRepository $deviceConfigurations,
    ) {
    }

    public static function fromDatabase(DashboardDatabase $database): self
    {
        $pdo = $database->pdo();

        return new self(
            new DashboardSupplierRepository($pdo),
            new DashboardModelRepository($pdo),
            new DashboardWhitelistRepository($pdo),
            new DashboardHistoryRepository($pdo),
            new DashboardDeviceConfigurationRepository($pdo),
        );
    }
}
