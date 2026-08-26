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
use Hub\Infrastructure\Persistence\Migration\Version2026080602DiaperTelemetrySections;
use Hub\Infrastructure\Persistence\Migration\Version2026080701AddMkgw4Gateway;
use Hub\Infrastructure\Persistence\Migration\Version2026080702EnableMkgw4GatewayCapabilities;
use Hub\Infrastructure\Persistence\Migration\Version2026081001BraceletDevices;
use Hub\Infrastructure\Persistence\Migration\Version2026081002UnifyHelpCallLabel;
use Hub\Infrastructure\Persistence\Migration\Version2026081101MigrateVivistarPhonebookRows;
use Hub\Infrastructure\Persistence\Migration\Version2026081102BackfillMissingModelCapabilities;
use Hub\Infrastructure\Persistence\Migration\Version2026081401DiaperMoistureLevelCapability;
use Hub\Infrastructure\Persistence\Migration\Version2026081901RemoveBraceletMotionCapability;
use Hub\Infrastructure\Persistence\Migration\Version2026081902RestoreBraceletMotionCapability;
use Hub\Infrastructure\Persistence\Migration\Version2026082101DiaperSensorSettings;
use Hub\Infrastructure\Persistence\Migration\Version2026082601DropSupplierEnabled;
use Hub\Infrastructure\Persistence\Migration\Version2026082701DropSupplierDeviceTypeEnabled;
use Hub\Infrastructure\Persistence\Migration\Version2026082801DiaperSensitivityAsCapability;
use Hub\Infrastructure\Persistence\Migration\Version2026082802DropDiaperSensorSettings;
use Hub\Infrastructure\Persistence\Migration\Version2026082803AddNotificationLicense;
use Hub\Infrastructure\Persistence\Migration\Version2026082804RadarCapabilityVocabulary;

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
            new Version2026080602DiaperTelemetrySections(),
            new Version2026080701AddMkgw4Gateway(),
            new Version2026080702EnableMkgw4GatewayCapabilities(),
            new Version2026081001BraceletDevices(),
            new Version2026081002UnifyHelpCallLabel(),
            new Version2026081101MigrateVivistarPhonebookRows(),
            new Version2026081102BackfillMissingModelCapabilities(),
            new Version2026081401DiaperMoistureLevelCapability(),
            new Version2026081901RemoveBraceletMotionCapability(),
            new Version2026081902RestoreBraceletMotionCapability(),
            new Version2026082101DiaperSensorSettings(),
            new Version2026082601DropSupplierEnabled(),
            new Version2026082701DropSupplierDeviceTypeEnabled(),
            new Version2026082801DiaperSensitivityAsCapability(),
            new Version2026082802DropDiaperSensorSettings(),
            new Version2026082803AddNotificationLicense(),
            new Version2026082804RadarCapabilityVocabulary(),
        ];
    }

    /** @return list<string> */
    public function versions(): array
    {
        return array_map(static fn(Migration $migration): string => $migration->version(), $this->migrations());
    }
}
