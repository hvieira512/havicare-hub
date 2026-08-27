#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Hub\Infrastructure\Persistence\InventorySeeder;
use Hub\Runtime\CliBootstrap;

/**
 * Enche uma base de dados vazia com o inventário capturado do hub de produção, para um clone
 * novo arrancar com os dispositivos reais em vez de um painel vazio.
 *
 * Não pode ser uma migração: o `DatabaseMigrator` também corre no modelo de base de dados
 * que os testes de integração clonam, e cada teste passaria a começar com vinte e seis
 * dispositivos. Migrações levam esquema; dados de arranque são um passo à parte, que os
 * testes não chamam.
 */
$config = CliBootstrap::config(__DIR__ . '/..');
$database = CliBootstrap::database($config, assertSchema: false);

$seeded = (new InventorySeeder())->seed($database->pdo());

fwrite(STDOUT, $seeded
    ? "Device inventory seeded.\n"
    : "Device inventory already present, nothing to seed.\n");
