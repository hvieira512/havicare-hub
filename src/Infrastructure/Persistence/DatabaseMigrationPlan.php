<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Migration\CapabilitySectionsIncludeReminders;
use Hub\Infrastructure\Persistence\Migration\Migration;
use Hub\Infrastructure\Persistence\Migration\WhitelistKeyCascadesOnRename;

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
        ];
    }

    /** @return list<string> */
    public function versions(): array
    {
        return array_map(static fn(Migration $migration): string => $migration->version(), $this->migrations());
    }
}
