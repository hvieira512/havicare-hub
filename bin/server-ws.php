#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use React\EventLoop\Loop;
use React\Socket\Server as Reactor;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use App\WebSocket\WatchServer;
use App\Bootstrap;
use App\Log\Logger;
use App\Tcp\VivistarTcpIngress;

$config = Bootstrap::config();

$wsPort = $config['websocket']['port'] ?? 8080;
$wsHost = $config['websocket']['host'] ?? '0.0.0.0';
$vivistarTcpPort = $config['vivistar_tcp']['port'] ?? 9000;
$vivistarTcpHost = $config['vivistar_tcp']['host'] ?? '0.0.0.0';

$pdo = Bootstrap::database($config['database'] ?? null);
Bootstrap::setupDeviceCapabilities($pdo);

$redis = Bootstrap::redis($config['redis'] ?? []);

$loop = Loop::get();

$watchServer = new WatchServer($pdo, $redis);

$wsApp = new HttpServer(
    new WsServer($watchServer)
);
$wsSocket = new Reactor("$wsHost:$wsPort", $loop);
$wsServer = new IoServer($wsApp, $wsSocket, $loop);

$vivistarTcpServer = new VivistarTcpIngress(
    watchServer: $watchServer,
    loop: $loop,
    host: $vivistarTcpHost,
    port: $vivistarTcpPort,
);

$loop->addPeriodicTimer(1.0, function () use ($watchServer): void {
    $watchServer->sweepCommandTimeouts();
});

if ($redis !== null && $redis->isAvailable()) {
    $redis->xGroupCreate('cmd:worker', 'cmd:stream', '0', true);
    Logger::channel('ws-cmd')->info("Group 'cmd:worker' ready on stream 'cmd:stream'");
    $consumerName = 'ws:' . (gethostname() ?: 'unknown');

    $loop->addPeriodicTimer(0.5, function () use ($redis, $watchServer, $consumerName) {
        try {
            $commands = $redis->commandReadPending('cmd:worker', $consumerName, 10);
            if (empty($commands)) {
                $commands = $redis->commandReadGroup('cmd:worker', $consumerName, 10, 100);
            }
            if (empty($commands)) return;

            $ackIds = [];
            foreach ($commands as $cmd) {
                $imei = $cmd['imei'];
                $type = $cmd['type'];
                $data = $cmd['data'];
                $requestId = $cmd['requestId'] ?: null;

                if ($cmd['feature'] !== '') {
                    $sent = $watchServer->sendFeatureCommand($imei, $cmd['feature'], $data, $requestId);
                } else {
                    $sent = $watchServer->sendCommand($imei, $type, $data, $requestId);
                }

                $mode = ($cmd['isPending'] ?? false) ? 'pending' : 'new';
                Logger::channel('ws-cmd')->info("mode=$mode streamId={$cmd['streamId']} IMEI=$imei type=$type " . ($sent ? 'sent' : 'failed'));
                $ackIds[] = $cmd['streamId'];
            }

            if (!empty($ackIds)) {
                $redis->xAck('cmd:stream', 'cmd:worker', $ackIds);
            }
        } catch (\Throwable $e) {
            Logger::channel('ws-cmd')->error("Error: {$e->getMessage()}");
        }
    });
    Logger::channel('ws-cmd')->info('Active: Redis Stream -> WebSocket commands');
}

Logger::channel('app')->info("=== Device Ingress Server (separate) ===");
Logger::channel('app')->info("ws://$wsHost:$wsPort");
Logger::channel('app')->info("tcp://$vivistarTcpHost:$vivistarTcpPort (Vivistar)");

$loop->run();
