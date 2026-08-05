#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Hub\Api\Repository\WhitelistRepository;
use Hub\Bootstrap;
use Hub\Config;
use Hub\Infrastructure\Persistence\DashboardDatabase;
use Hub\Infrastructure\Persistence\DatabaseSchemaGuard;
use Hub\Registry\WhitelistFileImporter;

Bootstrap::loadEnv(__DIR__ . '/..');
$config = Config::load()->all();
$options = getopt('', ['file:']);
$filePath = trim((string)($options['file'] ?? $config['hub']['whitelist_file'] ?? ''));
if ($filePath === '') {
    fwrite(STDERR, "Usage: php bin/import-whitelist.php --file=/path/to/whitelist.json\n");
    exit(2);
}

$database = new DashboardDatabase($config['database'] ?? []);
(new DatabaseSchemaGuard($database->pdo()))->assertCurrent();
$result = (new WhitelistFileImporter(new WhitelistRepository($database->pdo())))->import($filePath);

fwrite(STDOUT, sprintf("Whitelist import complete: %d imported, %d skipped.\n", $result['imported'], $result['skipped']));
