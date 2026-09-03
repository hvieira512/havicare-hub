<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Migration\DropConfigurationSupplierAndModel;
use Hub\Infrastructure\Persistence\Migration\DropSupplierDeviceTypes;
use Hub\Infrastructure\Persistence\Migration\Migration;
use Hub\Infrastructure\Persistence\Migration\ShrinkConfigurationLifecycle;

/**
 * As migrações posteriores à baseline, que é o `database/schema.sql` mais o catálogo que o
 * `DatabaseMigrator` semeia.
 *
 * Entram aqui as mudanças que uma base existente precisa de aplicar e que o `schema.sql`
 * sozinho não faz -- largar uma coluna, renomear, converter linhas. Uma instalação nova nasce
 * na baseline e não replica nada disto.
 *
 * Sai daqui o que já foi aplicado nas duas bases que existem e cujo destino uma base nova já
 * alcança pela baseline. Não há caminho de actualização a partir de antes da baseline.
 */
final class DatabaseMigrationPlan
{
    /** @return list<Migration> */
    public function migrations(): array
    {
        return [
            new ShrinkConfigurationLifecycle(),
            new DropSupplierDeviceTypes(),
            new DropConfigurationSupplierAndModel(),
        ];
    }

    /** @return list<string> */
    public function versions(): array
    {
        return array_map(static fn(Migration $migration): string => $migration->version(), $this->migrations());
    }
}
