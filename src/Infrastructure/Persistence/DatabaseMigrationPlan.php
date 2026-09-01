<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Migration\CatalogAlarmProximityAndHelpCall;
use Hub\Infrastructure\Persistence\Migration\Migration;

/**
 * As migrações posteriores à baseline, que é o `database/schema.sql` mais o catálogo de
 * referência que o `DatabaseMigrator` semeia a partir do `CapabilityCatalog`.
 *
 * O que entra aqui são mudanças de esquema ou de dados que uma base já existente precisa de
 * aplicar e que o `schema.sql` sozinho não consegue fazer: largar uma coluna, renomear,
 * converter linhas. Uma instalação nova não replica nada disto -- nasce na baseline.
 *
 * E o que sai daqui é o que já não tem trabalho para fazer: uma migração aplicada nas duas
 * bases que existem, produção e local, e cujo destino uma base nova já alcança pela baseline.
 * Nesse ponto a classe é só o caminho até um estado que o `schema.sql` e o
 * `ReferenceCatalogSeeder` já descrevem, e o registo dela fica em `schema_migrations` sem
 * consumidor. Está vazio porque nenhuma das anteriores tinha ainda trabalho pendente.
 *
 * Não há caminho de actualização a partir de uma base anterior à baseline.
 */
final class DatabaseMigrationPlan
{
    /** @return list<Migration> */
    public function migrations(): array
    {
        return [
            new CatalogAlarmProximityAndHelpCall(),
        ];
    }

    /** @return list<string> */
    public function versions(): array
    {
        return array_map(static fn(Migration $migration): string => $migration->version(), $this->migrations());
    }
}
