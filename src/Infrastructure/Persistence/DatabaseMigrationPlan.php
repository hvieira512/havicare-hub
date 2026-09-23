<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Migration\Migration;
use Hub\Infrastructure\Persistence\Migration\ModelImageFilenameOnly;
use Hub\Infrastructure\Persistence\Migration\PillDispenserCatalog;
use Hub\Infrastructure\Persistence\Migration\PillDispenserImage;
use Hub\Infrastructure\Persistence\Migration\PillDispenserReportedConfigurationCleanup;

/**
 * As migrações posteriores à baseline, que é o `database/schema.sql` mais o catálogo que o
 * `DatabaseMigrator` semeia.
 *
 * Entram aqui as mudanças que uma base existente precisa de aplicar e que o `schema.sql`
 * sozinho não faz -- largar uma coluna, renomear, converter linhas. Uma instalação nova nasce
 * na baseline e não replica nada disto.
 *
 * **O catálogo de capacidades não entra.** O `DatabaseMigrator` reconcilia-o a partir do
 * código a cada arranque, e por isso uma etiqueta, uma secção, uma bandeira ou uma capacidade
 * inteira que mude no `CapabilityCatalog` chega à base sozinha. Doze migrações que não faziam
 * outra coisa saíram daqui quando essa reconciliação passou a existir.
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
            // O fornecedor, o modelo e o tipo de dispositivo. O semeador só os cria numa base
            // vazia, e as duas que existem já não estavam quando o dispensador chegou.
            new PillDispenserCatalog(),
            // A configuração que o aparelho tem passa a ser guardada pela chave certa.
            new PillDispenserReportedConfigurationCleanup(),
            // O modelo ganha a fotografia que o fornecedor publica.
            new PillDispenserImage(),
            // E a imagem passa a ser guardada pelo nome, sem a rota que é código.
            new ModelImageFilenameOnly(),
        ];
    }

    /** @return list<string> */
    public function versions(): array
    {
        return array_map(static fn(Migration $migration): string => $migration->version(), $this->migrations());
    }
}
