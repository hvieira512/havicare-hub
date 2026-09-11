<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Infrastructure\Persistence\ReferenceCatalogSeeder;
use PDO;

/**
 * Põe o catálogo de pulseira de acordo com o que o código declara.
 *
 * As migrações anteriores acrescentavam capacidades uma a uma e ligavam-nas aos modelos que
 * já tivessem uma chave escolhida à mão como âncora. Onde essa âncora não existia -- e
 * `heart_rate_continuous` não existia na base de desenvolvimento -- a capacidade entrava no
 * catálogo e não chegava a nenhum modelo: a dashboard não a oferecia, e nada o dizia.
 *
 * Aqui não há âncora. As capacidades em falta vêm das definições em código, e a ligação aos
 * modelos é a do semeador, que já sabe cruzar o protocolo de cada modelo com as chaves dele.
 */
final class BraceletCatalogFromCode implements Migration
{
    public function version(): string
    {
        return '2026_09_11_bracelet_catalog_from_code';
    }

    public function up(PDO $pdo): void
    {
        // Numa base por semear o seeder escreve já o catálogo completo.
        if ((int)$pdo->query('SELECT COUNT(*) FROM capabilities')->fetchColumn() === 0) {
            return;
        }

        $insert = $pdo->prepare('
            INSERT IGNORE INTO capabilities (device_type, section, capability_key, label, is_configurable, is_requestable)
            VALUES (?, ?, ?, ?, ?, ?)
        ');

        foreach (CapabilityCatalog::definitionsForDeviceType('bracelet') as $definition) {
            $insert->execute([
                'bracelet',
                (string)$definition['section'],
                (string)$definition['key'],
                (string)$definition['label'],
                ($definition['isConfigurable'] ?? false) ? 1 : 0,
                ($definition['isRequestable'] ?? false) ? 1 : 0,
            ]);
        }

        (new ReferenceCatalogSeeder())->seedMissingModelCapabilities($pdo);
    }
}
