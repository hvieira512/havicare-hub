#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use React\EventLoop\Loop;
use App\Http\ApiServer;
use App\Bootstrap;
use App\Log\Logger;

$config = Bootstrap::config();

$apiPort = $config['api']['port'] ?? 8081;
$apiHost = $config['api']['host'] ?? '0.0.0.0';
$wsServerUrl = getenv('WS_SERVER_URL') ?: ($config['public_ws_url'] ?? 'ws://127.0.0.1:8080');

$pdo = Bootstrap::database($config['database'] ?? null);
Bootstrap::setupDeviceCapabilities($pdo);

$redis = Bootstrap::redis($config['redis'] ?? []);

$loop = Loop::get();

$apiServer = new ApiServer(
    watchServer: null,
    loop: $loop,
    port: $apiPort,
    host: $apiHost,
    pdo: $pdo,
    redis: $redis,
    wsServerUrl: $wsServerUrl,
);

Logger::channel('app')->info("=== HTTP API Server (separate) ===");
Logger::channel('app')->info("http://$apiHost:$apiPort");

$loop->run();
