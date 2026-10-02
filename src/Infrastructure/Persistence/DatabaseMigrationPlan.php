<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Migration\Migration;
use Hub\Infrastructure\Persistence\Migration\WhitelistKeyCascadesOnRename;

/**
 * As migrações posteriores à baseline, que é o `database/schema.sql` mais o catálogo que o
 * `DatabaseMigrator` semeia.
 *
 * Entram aqui as mudanças que uma base existente precisa de aplicar e que o `schema.sql`
 * sozinho não faz -- largar uma coluna, renomear, converter linhas. Uma instalação nova nasce
 * na baseline, e não há caminho de actualização a partir de antes dela.
 *
 * **O catálogo de capacidades não entra:** o `DatabaseMigrator` reconcilia-o do código a cada
 * arranque, e doze migrações que não faziam outra coisa saíram daqui por causa disso.
 *
 * Uma migração sai daqui quando as instâncias todas a têm aplicada e uma base nova chega ao
 * mesmo estado sem ela. O `DatabaseSchemaGuard` só exige que o que está
 * aqui esteja aplicado, e por isso as linhas que sobram na `schema_migrations` não incomodam.
 */
final class DatabaseMigrationPlan
{
    /** @return list<Migration> */
    public function migrations(): array
    {
        return [
            new WhitelistKeyCascadesOnRename(),
        ];
    }

    /** @return list<string> */
    public function versions(): array
    {
        return array_map(static fn(Migration $migration): string => $migration->version(), $this->migrations());
    }
}
