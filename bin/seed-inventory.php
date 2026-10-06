#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Hub\Infrastructure\Persistence\InventorySeeder;
use Hub\Runtime\CliBootstrap;

/**
 * Enche uma base de dados vazia com o inventário capturado da produção. Não é migração porque
 * os testes de integração clonam a base migrada e começariam todos com dispositivos.
 */
$config = CliBootstrap::config(__DIR__ . '/..');
$database = CliBootstrap::database($config, assertSchema: false);

$seeder = new InventorySeeder();
$copiedImages = $seeder->copyMissingModelImages();
$seeded = $seeder->seed($database->pdo());

fwrite(STDOUT, $seeded
    ? "Device inventory seeded.\n"
    : "Device inventory already present, nothing to seed.\n");
fwrite(STDOUT, sprintf("Model images copied: %d.\n", $copiedImages));
