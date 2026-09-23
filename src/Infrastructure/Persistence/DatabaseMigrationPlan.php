<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Migration\Migration;
use Hub\Infrastructure\Persistence\Migration\PillDispenserCatalog;
use Hub\Infrastructure\Persistence\Migration\PillDispenserAlarmStatus;
use Hub\Infrastructure\Persistence\Migration\ModelImageFilenameOnly;
use Hub\Infrastructure\Persistence\Migration\PillDispenserImage;
use Hub\Infrastructure\Persistence\Migration\PillDispenserParameterDiscovery;
use Hub\Infrastructure\Persistence\Migration\PillDispenserReportedConfigurationCleanup;
use Hub\Infrastructure\Persistence\Migration\PillDispenserRetrievalSettings;
use Hub\Infrastructure\Persistence\Migration\PillDispenserCatalogueTidyUp;
use Hub\Infrastructure\Persistence\Migration\PillDispenserWithoutSimCard;
use Hub\Infrastructure\Persistence\Migration\PillDispenserWithoutUnservedControls;
use Hub\Infrastructure\Persistence\Migration\PillDispenserWithoutEncryptionSwitch;
use Hub\Infrastructure\Persistence\Migration\PillDispenserWithoutFactoryReset;

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
            // O dispensador de comprimidos é um tipo de dispositivo novo, e as duas bases já
            // existiam quando ele chegou.
            new PillDispenserCatalog(),
            // O primeiro M228 real mostrou que a reposição de fábrica não devia estar ao
            // alcance de um clique.
            new PillDispenserWithoutFactoryReset(),
            // E o fornecedor confirmou que a cifra não se desliga por configuração.
            new PillDispenserWithoutEncryptionSwitch(),
            // A toma de medicação passa a ler-se pelo estado dos alarmes, que vem em claro.
            new PillDispenserAlarmStatus(),
            // E a configuração que o aparelho tem passa a ser guardada pela chave certa.
            new PillDispenserReportedConfigurationCleanup(),
            // O aparelho passa a poder dizer que parâmetros serve, em vez de se adivinhar.
            new PillDispenserParameterDiscovery(),
            // E o modelo ganha a fotografia que o fornecedor publica.
            new PillDispenserImage(),
            // A imagem de um modelo passa a ser guardada pelo nome, sem a rota que é código.
            new ModelImageFilenameOnly(),
            // E os tempos da toma deixam de se mudar por script.
            new PillDispenserRetrievalSettings(),
            // Duas dessas ordens este firmware não as serve, e o aparelho disse-o.
            new PillDispenserWithoutUnservedControls(),
            // E o catálogo arruma-se para quem nunca viu o aparelho o conseguir administrar.
            new PillDispenserCatalogueTidyUp(),
            new PillDispenserWithoutSimCard(),
        ];
    }

    /** @return list<string> */
    public function versions(): array
    {
        return array_map(static fn(Migration $migration): string => $migration->version(), $this->migrations());
    }
}
