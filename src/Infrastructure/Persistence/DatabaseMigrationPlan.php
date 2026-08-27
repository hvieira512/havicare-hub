<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Migration\Migration;
use Hub\Infrastructure\Persistence\Migration\Version2026082805DropDiaperSensorSettingsAgain;

/**
 * As migrações posteriores à baseline.
 *
 * A baseline é o `database/schema.sql` mais o catálogo de referência que o
 * `DatabaseMigrator` semeia a partir do `CapabilityCatalog`. Até 2026-08-28 havia aqui
 * trinta e oito migrações que, juntas, produziam exactamente esse estado -- e ficou
 * provado por medição antes de serem apagadas: uma base feita só do `schema.sql` mais o
 * seed tem as mesmas tabelas, colunas, índices e chaves estrangeiras, e o mesmo catálogo
 * ao registo.
 *
 * Vinte e três delas não tocavam no esquema. Acrescentavam uma capacidade, corrigiam uma
 * etiqueta, mudavam uma secção -- cada uma a empurrar a base de dados um passo na direcção
 * do que o código já dizia. Esse estado é agora uma função da versão que está a correr, e
 * não do histórico: quem instala de novo não replica um ano de correcções para chegar onde
 * o `CapabilityCatalog` já está.
 *
 * **O que se perdeu, deliberadamente:** o caminho de actualização a partir de uma base
 * anterior à baseline, que a `2026072401_upgrade_legacy_schema` fazia. As duas bases que
 * existem -- produção e local -- estão ambas para lá dela, e as classes continuam no
 * histórico do git para quem precise de as ler.
 *
 * O que entra aqui a partir de agora são migrações normais: mudanças de esquema ou de
 * dados que uma base já existente precisa de aplicar e que o `schema.sql` sozinho não
 * consegue fazer -- largar uma coluna, renomear, converter linhas.
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
