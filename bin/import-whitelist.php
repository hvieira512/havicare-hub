#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Hub\Api\Repository\WhitelistRepository;
use Hub\Registry\WhitelistFileImporter;
use Hub\Runtime\CliBootstrap;

$config = CliBootstrap::config(__DIR__ . '/..');
$options = getopt('', ['file:']);
$filePath = trim((string)($options['file'] ?? $config['hub']['whitelist_file'] ?? ''));
if ($filePath === '') {
    fwrite(STDERR, "Usage: php bin/import-whitelist.php --file=/path/to/whitelist.json\n");
    exit(2);
}

$database = CliBootstrap::database($config);
$result = (new WhitelistFileImporter(new WhitelistRepository($database->pdo())))->import($filePath);

fwrite(STDOUT, sprintf("Whitelist import complete: %d imported, %d skipped.\n", $result['imported'], $result['skipped']));
