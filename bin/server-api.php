#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use React\EventLoop\Loop;
use App\Http\ApiServer;
use App\Bootstrap;
use App\Log\Logger;
use App\Runtime\ServiceComposer;

$config = Bootstrap::config();

$apiPort = $config['api']['port'] ?? 8081;
$apiHost = $config['api']['host'] ?? '0.0.0.0';
$wsServerUrl = getenv('WS_SERVER_URL') ?: ($config['public_ws_url'] ?? 'ws://127.0.0.1:8080');

$pdo = Bootstrap::database($config['database'] ?? null);

$redis = Bootstrap::redis($config['redis'] ?? []);
$apiServices = ServiceComposer::forApi($pdo, $redis, null);

$loop = Loop::get();

$apiServer = new ApiServer(
    watchServer: null,
    loop: $loop,
    port: $apiPort,
    host: $apiHost,
    pdo: $pdo,
    redis: $redis,
    wsServerUrl: $wsServerUrl,
    deviceService: $apiServices['deviceService'],
    commandService: $apiServices['commandService'],
    eventService: $apiServices['eventService'],
    systemService: $apiServices['systemService'],
);

Logger::channel('app')->info("=== HTTP API Server (separate) ===");
Logger::channel('app')->info("http://$apiHost:$apiPort");

$loop->run();
