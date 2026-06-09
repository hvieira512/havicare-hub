#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Hub\DeviceHubServer;
use App\Hub\HubDownlinkSubscriber;
use App\Hub\HubMqttBridge;
use App\Hub\HubTcpIngress;
use App\Log\Logger;
use App\Mqtt\SimpleClient;
use App\Registry\Whitelist;
use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use React\Socket\Server as Reactor;

$config = Config::load()->all();
$mqttConfig = $config['mqtt'] ?? [];

$mqttHost = trim((string)($mqttConfig['host'] ?? ''));
if ($mqttHost === '') {
    Logger::channel('hub')->error('MQTT_HOST is required for the devices hub');
    exit(1);
}

$clientIdPrefix = preg_replace('/[^a-zA-Z0-9_-]/', '-', (string)($mqttConfig['client_id_prefix'] ?? 'hitecosystem-hub')) ?: 'hitecosystem-hub';
$topicPrefix = trim((string)($mqttConfig['topic_prefix'] ?? ''), '/');

$buildMqttClient = static function (string $suffix) use ($mqttConfig, $mqttHost, $clientIdPrefix): SimpleClient {
    return new SimpleClient(
        host: $mqttHost,
        port: (int)($mqttConfig['port'] ?? 1883),
        clientId: substr($clientIdPrefix . '-' . $suffix . '-' . getmypid(), 0, 23),
        username: (string)($mqttConfig['username'] ?? ''),
        password: (string)($mqttConfig['password'] ?? ''),
        keepAlive: (int)($mqttConfig['keepalive'] ?? 60),
        timeout: (float)($mqttConfig['timeout'] ?? 5.0),
        tlsEnabled: (bool)($mqttConfig['tls_enabled'] ?? false),
        tlsVerifyPeer: (bool)($mqttConfig['tls_verify_peer'] ?? true),
        tlsCaFile: (string)($mqttConfig['tls_ca_file'] ?? ''),
        tlsCertFile: (string)($mqttConfig['tls_cert_file'] ?? ''),
        tlsKeyFile: (string)($mqttConfig['tls_key_file'] ?? ''),
    );
};

$whitelist = new Whitelist();
$mqttBridge = new HubMqttBridge($buildMqttClient('pub'), $topicPrefix);
$hubServer = new DeviceHubServer($whitelist, $mqttBridge);
$downlink = new HubDownlinkSubscriber($buildMqttClient('sub'), $hubServer, $topicPrefix);

$loop = Loop::get();
$wsHost = $config['websocket']['host'] ?? '0.0.0.0';
$wsPort = $config['websocket']['port'] ?? 8080;
$tcpHost = $config['vivistar_tcp']['host'] ?? '0.0.0.0';
$tcpPort = $config['vivistar_tcp']['port'] ?? 9000;

$wsSocket = new Reactor("$wsHost:$wsPort", $loop);
$wsServer = new IoServer(new HttpServer(new WsServer($hubServer)), $wsSocket, $loop);
$tcpIngress = new HubTcpIngress($hubServer, $loop, $tcpHost, $tcpPort);

try {
    $downlink->start();
} catch (\Throwable $e) {
    Logger::channel('hub')->error('MQTT downlink subscription failed: ' . $e->getMessage());
    exit(1);
}

$loop->addPeriodicTimer(0.05, function () use ($downlink): void {
    try {
        $downlink->tick(0.001);
    } catch (\Throwable $e) {
        Logger::channel('hub')->error('MQTT downlink loop failed: ' . $e->getMessage());
    }
});

Logger::channel('hub')->info('=== Hitecosystem Devices Hub ===');
Logger::channel('hub')->info("WebSocket ingress: ws://$wsHost:$wsPort");
Logger::channel('hub')->info("TCP ingress: tcp://$tcpHost:$tcpPort");
Logger::channel('hub')->info('MQTT uplink topics: ' . $mqttBridge->topic('devices/{imei}/uplink'));
Logger::channel('hub')->info('MQTT downlink topics: ' . $mqttBridge->topic('devices/{imei}/downlink'));

$loop->run();
