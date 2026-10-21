<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Migration\DropUnreadColumns;
use Hub\Infrastructure\Persistence\Migration\Migration;

/**
 * As migrações posteriores à baseline (`schema.sql` e catálogo semeado). Uma migração sai daqui
 * quando todas as instâncias a têm e uma base nova chega lá sem ela.
 */
final class DatabaseMigrationPlan
{
    /** @return list<Migration> */
    public function migrations(): array
    {
        return [
            new DropUnreadColumns(),
        ];
    }

    /** @return list<string> */
    public function versions(): array
    {
        return array_map(static fn(Migration $migration): string => $migration->version(), $this->migrations());
    }
}
