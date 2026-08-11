#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Hub\Location\PrivateRadioMapFactory;
use Hub\Runtime\CliBootstrap;

// $argv only exists when register_argc_argv is on; $_SERVER always carries it.
/** @var list<string> $arguments */
$arguments = (array)($_SERVER['argv'] ?? []);

$config = CliBootstrap::config(__DIR__ . '/..');
$locationConfig = $config['location_resolution'] ?? [];
if (!(bool)($locationConfig['radio_map_enabled'] ?? true)) {
    fwrite(STDERR, "Private radio map is disabled\n");
    exit(2);
}
if (trim((string)($locationConfig['radio_map_hash_key'] ?? '')) === '') {
    fwrite(STDERR, "RADIO_MAP_HASH_KEY is required for radio-map administration\n");
    exit(2);
}

$command = $arguments[1] ?? '';
if ($command !== 'seed') {
    fwrite(STDERR, "Usage: php bin/radio-map.php seed --lat=... --lon=... --accuracy=... --bssids=mac1,mac2\n");
    exit(2);
}

$options = [];
foreach (array_slice($arguments, 2) as $argument) {
    if (preg_match('/^--(lat|lon|accuracy|bssids)=(.*)$/', $argument, $matches) === 1) {
        $options[$matches[1]] = $matches[2];
    }
}
$lat = $options['lat'] ?? null;
$lon = $options['lon'] ?? null;
$accuracy = $options['accuracy'] ?? null;
$bssids = array_values(array_filter(array_map(
    static fn (string $value): string => trim($value),
    explode(',', (string)($options['bssids'] ?? '')),
)));
if (!is_numeric($lat) || !is_numeric($lon) || !is_numeric($accuracy) || $bssids === []) {
    fwrite(STDERR, "lat, lon, accuracy and bssids are required\n");
    exit(2);
}

try {
    $database = CliBootstrap::database($config);
    $count = PrivateRadioMapFactory::create($database->pdo(), $locationConfig)->seed(
        $bssids,
        (float)$lat,
        (float)$lon,
        (float)$accuracy,
    );
    echo json_encode([
        'status' => 'ok',
        'seededAccessPoints' => $count,
        'accuracyMeters' => (float)$accuracy,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
