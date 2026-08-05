#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Hub\Bootstrap;
use Hub\Config;
use Hub\Infrastructure\Persistence\DashboardDatabase;
use Hub\Infrastructure\Persistence\DatabaseMigrator;

Bootstrap::loadEnv(__DIR__ . '/..');
$config = Config::load()->all();
$database = new DashboardDatabase($config['database'] ?? []);
(new DatabaseMigrator($database->pdo()))->migrate();

fwrite(STDOUT, "Database migrations applied successfully.\n");
