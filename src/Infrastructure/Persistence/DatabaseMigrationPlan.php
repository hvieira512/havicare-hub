<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Migration\Migration;
use Hub\Infrastructure\Persistence\Migration\Version2026082805DropDiaperSensorSettingsAgain;

/**
 * As migrações posteriores à baseline, que é o `database/schema.sql` mais o catálogo de
 * referência que o `DatabaseMigrator` semeia a partir do `CapabilityCatalog`.
 *
 * O que entra aqui são mudanças de esquema ou de dados que uma base já existente precisa de
 * aplicar e que o `schema.sql` sozinho não consegue fazer: largar uma coluna, renomear,
 * converter linhas. Uma instalação nova não replica nada disto -- nasce na baseline.
 *
 * Não há caminho de actualização a partir de uma base anterior à baseline. As duas que
 * existem, produção e local, estão ambas para lá dela.
 */
final class DatabaseMigrationPlan
{
    /** @return list<Migration> */
    public function migrations(): array
    {
        return [
            new Version2026082805DropDiaperSensorSettingsAgain(),
        ];
    }

    /** @return list<string> */
    public function versions(): array
    {
        return array_map(static fn(Migration $migration): string => $migration->version(), $this->migrations());
    }
}
