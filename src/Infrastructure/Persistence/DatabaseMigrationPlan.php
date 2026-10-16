<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Migration\CapabilitySectionsIncludeReminders;
use Hub\Infrastructure\Persistence\Migration\ConfigurationOperationTarget;
use Hub\Infrastructure\Persistence\Migration\JoyTechCatalog;
use Hub\Infrastructure\Persistence\Migration\JoyTechEcgMonitorCatalog;
use Hub\Infrastructure\Persistence\Migration\JoyTechThermometerCatalog;
use Hub\Infrastructure\Persistence\Migration\Migration;
use Hub\Infrastructure\Persistence\Migration\QinglanstRadarIsHc2;
use Hub\Infrastructure\Persistence\Migration\UnconfirmedHeartRateAlarmIsUnlinked;
use Hub\Infrastructure\Persistence\Migration\UniqueScalesCatalog;
use Hub\Infrastructure\Persistence\Migration\UniqueScalesImages;
use Hub\Infrastructure\Persistence\Migration\WhitelistKeyCascadesOnRename;
use Hub\Infrastructure\Persistence\Migration\WifiSosButtonCatalog;
use Hub\Infrastructure\Persistence\Migration\WifiSosButtonImage;

/**
 * As migrações posteriores à baseline (`schema.sql` e catálogo semeado), sem o de capacidades.
 * Uma migração sai daqui quando todas as instâncias a têm e uma base nova chega lá sem ela.
 */
final class DatabaseMigrationPlan
{
    /** @return list<Migration> */
    public function migrations(): array
    {
        return [
            new WhitelistKeyCascadesOnRename(),
            new CapabilitySectionsIncludeReminders(),
            new UnconfirmedHeartRateAlarmIsUnlinked(),
            new WifiSosButtonCatalog(),
            new WifiSosButtonImage(),
            new UniqueScalesCatalog(),
            new ConfigurationOperationTarget(),
            new UniqueScalesImages(),
            new QinglanstRadarIsHc2(),
            new JoyTechCatalog(),
            new JoyTechThermometerCatalog(),
            new JoyTechEcgMonitorCatalog(),
        ];
    }

    /** @return list<string> */
    public function versions(): array
    {
        return array_map(static fn(Migration $migration): string => $migration->version(), $this->migrations());
    }
}
