#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Hub\Infrastructure\Persistence\DatabaseMigrator;
use Hub\Runtime\CliBootstrap;

$config = CliBootstrap::config(__DIR__ . '/..');
$database = CliBootstrap::database($config, assertSchema: false);
(new DatabaseMigrator($database->pdo()))->migrate();

fwrite(STDOUT, "Database migrations applied successfully.\n");
