#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Hub\Infrastructure\Persistence\InventorySeeder;
use Hub\Runtime\CliBootstrap;

/**
 * Enche uma base de dados vazia com o inventario capturado do hub de producao, para um
 * clone novo arrancar com os dispositivos reais em vez de um painel vazio.
 *
 * Isto era uma migracao, e nao podia ser: o `DatabaseMigrator` tambem corre no modelo de
 * base de dados que os testes de integracao clonam, e cada teste passou a comecar com
 * vinte e seis dispositivos. Um contava quatro e encontrava vinte e nove; outro inseria o
 * sensor de fraldas e batia na chave primaria. Migracoes levam esquema; dados de arranque
 * sao um passo a parte, que os testes nao chamam.
 */
$config = CliBootstrap::config(__DIR__ . '/..');
$database = CliBootstrap::database($config, assertSchema: false);

$seeded = (new InventorySeeder())->seed($database->pdo());

fwrite(STDOUT, $seeded
    ? "Device inventory seeded.\n"
    : "Device inventory already present, nothing to seed.\n");
