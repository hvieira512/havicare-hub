<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Migration\Migration;
use Hub\Infrastructure\Persistence\Migration\Version2026072401UpgradeLegacySchema;
use Hub\Infrastructure\Persistence\Migration\Version2026072402SeedReferenceCatalog;
use Hub\Infrastructure\Persistence\Migration\Version2026072403RebuildModelCapabilities;
use Hub\Infrastructure\Persistence\Migration\Version2026072404SeedModelCapabilities;
use Hub\Infrastructure\Persistence\Migration\Version2026072405NormalizeConfigurationKeys;
use Hub\Infrastructure\Persistence\Migration\Version2026072406AddDashboardNotifications;
use Hub\Infrastructure\Persistence\Migration\Version2026072801SyncWonlexAdultHealthCapabilities;
use Hub\Infrastructure\Persistence\Migration\Version2026072901CleanWatchCapabilityTaxonomy;
use Hub\Infrastructure\Persistence\Migration\Version2026072902EnumCapabilitySections;
use Hub\Infrastructure\Persistence\Migration\Version2026072903RestrictHw20ProHealthRequests;
use Hub\Infrastructure\Persistence\Migration\Version2026072904RemoveUnsupportedWonlexReports;
use Hub\Infrastructure\Persistence\Migration\Version2026072905NormalizeContactCapabilities;
use Hub\Infrastructure\Persistence\Migration\Version2026073001RenameFourPTouchWhitelistSwitch;
use Hub\Infrastructure\Persistence\Migration\Version2026073002CanonicalizeFourPTouchContactSlots;
use Hub\Infrastructure\Persistence\Migration\Version2026073003ConfigurationLifecycle;
use Hub\Infrastructure\Persistence\Migration\Version2026073101PrivateRadioMap;
use Hub\Infrastructure\Persistence\Migration\Version2026080301EnableWonlexPushMessage;
use Hub\Infrastructure\Persistence\Migration\Version2026080501ScopeApiUsersByLicense;
use Hub\Infrastructure\Persistence\Migration\Version2026080502RemoveWeatherCapability;
use Hub\Infrastructure\Persistence\Migration\Version2026080503NormalizeCapabilityLabelsPtPt;
use Hub\Infrastructure\Persistence\Migration\Version2026080601GatewayDiaperDevices;

final class DatabaseMigrationPlan
{
    /** @return list<Migration> */
    public function migrations(): array
    {
        return [
            new Version2026072401UpgradeLegacySchema(),
            new Version2026072402SeedReferenceCatalog(),
            new Version2026072403RebuildModelCapabilities(),
            new Version2026072404SeedModelCapabilities(),
            new Version2026072405NormalizeConfigurationKeys(),
            new Version2026072406AddDashboardNotifications(),
            new Version2026072801SyncWonlexAdultHealthCapabilities(),
            new Version2026072901CleanWatchCapabilityTaxonomy(),
            new Version2026072902EnumCapabilitySections(),
            new Version2026072903RestrictHw20ProHealthRequests(),
            new Version2026072904RemoveUnsupportedWonlexReports(),
            new Version2026072905NormalizeContactCapabilities(),
            new Version2026073001RenameFourPTouchWhitelistSwitch(),
            new Version2026073002CanonicalizeFourPTouchContactSlots(),
            new Version2026073003ConfigurationLifecycle(),
            new Version2026073101PrivateRadioMap(),
            new Version2026080301EnableWonlexPushMessage(),
            new Version2026080501ScopeApiUsersByLicense(),
            new Version2026080502RemoveWeatherCapability(),
            new Version2026080503NormalizeCapabilityLabelsPtPt(),
            new Version2026080601GatewayDiaperDevices(),
        ];
    }

    /** @return list<string> */
    public function versions(): array
    {
        return array_map(static fn(Migration $migration): string => $migration->version(), $this->migrations());
    }
}
