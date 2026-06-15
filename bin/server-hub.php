#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Bootstrap;
use App\Config;
use App\Hub\DeviceHubServer;
use App\Hub\HubDownlinkSubscriber;
use App\Hub\HubMqttBridge;
use App\Hub\HubTcpIngress;
use App\Hub\RedisPendingDownlinkQueue;
use App\Log\Logger;
use App\Registry\Whitelist;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\Contracts\Repository;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\Repositories\MemoryRepository;
use PhpMqtt\Client\Subscription;
use Predis\Client as RedisClient;
use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use React\Socket\SocketServer;

Bootstrap::loadEnv(__DIR__ . '/..');

$config = Config::load()->all();
$mqttConfig = $config['mqtt'] ?? [];
$redisConfig = $config['redis'] ?? [];
$downlinkQueueTtlSeconds = (int)($config['hub']['downlink_queue_ttl_seconds'] ?? 300);

$mqttHost = trim((string)($mqttConfig['host'] ?? ''));
if ($mqttHost === '') {
    Logger::channel('hub')->error('MQTT_HOST is required for the devices hub');
    exit(1);
}

$clientIdPrefix = preg_replace('/[^a-zA-Z0-9_-]/', '-', (string)($mqttConfig['client_id_prefix'] ?? 'hitecosystem-hub')) ?: 'hitecosystem-hub';
$topicPrefix = trim((string)($mqttConfig['topic_prefix'] ?? ''), '/');

$createMqttClient = static function (string $suffix, bool $stableClientId = false, ?Repository $repository = null) use ($mqttConfig, $mqttHost, $clientIdPrefix): MqttClient {
    $clientId = $stableClientId
        ? substr($clientIdPrefix . '-' . $suffix, 0, 23)
        : substr($clientIdPrefix . '-' . $suffix . '-' . getmypid(), 0, 23);

    return new MqttClient($mqttHost, (int)($mqttConfig['port'] ?? 1883), $clientId, MqttClient::MQTT_3_1_1, $repository);
};

$connectMqttClient = static function (MqttClient $client, bool $cleanSession = true) use ($mqttConfig): MqttClient {
    $username = (string)($mqttConfig['username'] ?? '');
    $password = (string)($mqttConfig['password'] ?? '');
    $timeout = max(1, (int)ceil((float)($mqttConfig['timeout'] ?? 5.0)));

    $settings = (new ConnectionSettings())
        ->setUsername($username !== '' ? $username : null)
        ->setPassword($password !== '' ? $password : null)
        ->setKeepAliveInterval(max(1, (int)($mqttConfig['keepalive'] ?? 60)))
        ->setConnectTimeout($timeout)
        ->setSocketTimeout($timeout)
        ->setUseTls((bool)($mqttConfig['tls_enabled'] ?? false))
        ->setTlsVerifyPeer((bool)($mqttConfig['tls_verify_peer'] ?? true))
        ->setTlsVerifyPeerName((bool)($mqttConfig['tls_verify_peer'] ?? true))
        ->setTlsSelfSignedAllowed(!(bool)($mqttConfig['tls_verify_peer'] ?? true))
        ->setTlsCertificateAuthorityFile(((string)($mqttConfig['tls_ca_file'] ?? '')) !== '' ? (string)$mqttConfig['tls_ca_file'] : null)
        ->setTlsClientCertificateFile(((string)($mqttConfig['tls_cert_file'] ?? '')) !== '' ? (string)$mqttConfig['tls_cert_file'] : null)
        ->setTlsClientCertificateKeyFile(((string)($mqttConfig['tls_key_file'] ?? '')) !== '' ? (string)$mqttConfig['tls_key_file'] : null);

    $client->connect($settings, $cleanSession);

    return $client;
};

$buildMqttClient = static fn (string $suffix, bool $cleanSession = true, bool $stableClientId = false): MqttClient
    => $connectMqttClient($createMqttClient($suffix, $stableClientId), $cleanSession);

$whitelistFile = trim((string)($config['hub']['whitelist_file'] ?? ''));
$whitelist = new Whitelist($whitelistFile !== '' ? $whitelistFile : null);
$redisParameters = [
    'host' => (string)($redisConfig['host'] ?? '127.0.0.1'),
    'port' => (int)($redisConfig['port'] ?? 6379),
];
$redisPassword = (string)($redisConfig['password'] ?? '');
if ($redisPassword !== '') {
    $redisParameters['password'] = $redisPassword;
}
$downlinkQueue = new RedisPendingDownlinkQueue(new RedisClient($redisParameters));
$mqttBridge = new HubMqttBridge(
    $buildMqttClient('pub'),
    $topicPrefix,
    static fn (): MqttClient => $buildMqttClient('pub')
);
$hubServer = new DeviceHubServer(
    $whitelist,
    $mqttBridge,
    downlinkQueue: $downlinkQueue,
    downlinkQueueTtlSeconds: $downlinkQueueTtlSeconds
);
$downlink = null;
$downlinkTopicFilter = $topicPrefix === '' ? 'devices/+/downlink' : $topicPrefix . '/devices/+/downlink';
$subscriberRepository = new MemoryRepository();
$subscriberRepository->addSubscription(new Subscription(
    $downlinkTopicFilter,
    MqttClient::QOS_AT_LEAST_ONCE,
    static function (string $topic, string $message) use (&$downlink): void {
        $downlink?->handleReceivedMessage($topic, $message);
    }
));
$subscriber = $createMqttClient('sub', true, $subscriberRepository);
$downlink = new HubDownlinkSubscriber(
    $subscriber,
    $hubServer,
    $topicPrefix,
    function () use (&$downlink, $createMqttClient, $connectMqttClient, $downlinkTopicFilter): MqttClient {
        $repository = new MemoryRepository();
        $repository->addSubscription(new Subscription(
            $downlinkTopicFilter,
            MqttClient::QOS_AT_LEAST_ONCE,
            static function (string $topic, string $message) use (&$downlink): void {
                $downlink?->handleReceivedMessage($topic, $message);
            }
        ));

        return $connectMqttClient($createMqttClient('sub', true, $repository), false);
    }
);
$connectMqttClient($subscriber, false);

$loop = Loop::get();
$wsHost = $config['websocket']['host'] ?? '0.0.0.0';
$wsPort = $config['websocket']['port'] ?? 8080;
$tcpHost = $config['vivistar_tcp']['host'] ?? '0.0.0.0';
$tcpPort = $config['vivistar_tcp']['port'] ?? 9000;

$wsEnabled = (bool)($config['websocket']['enabled'] ?? true);

if ($wsEnabled) {
    $wsSocket = new SocketServer("$wsHost:$wsPort", [], $loop);
    $wsServer = new IoServer(new HttpServer(new WsServer($hubServer)), $wsSocket, $loop);
}

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

if ($wsEnabled) {
    Logger::channel('hub')->info("WebSocket ingress: ws://$wsHost:$wsPort");
} else {
    Logger::channel('hub')->info('WebSocket ingress disabled');
}

Logger::channel('hub')->info("TCP ingress: tcp://$tcpHost:$tcpPort");
Logger::channel('hub')->info("Redis downlink queue: {$redisParameters['host']}:{$redisParameters['port']} ttl={$downlinkQueueTtlSeconds}s");
Logger::channel('hub')->info('MQTT status topics: ' . $mqttBridge->topic('devices/{imei}/status'));
Logger::channel('hub')->info('MQTT event topics: ' . $mqttBridge->topic('devices/{imei}/events'));
Logger::channel('hub')->info('MQTT raw topics: ' . $mqttBridge->topic('devices/{imei}/raw'));
Logger::channel('hub')->info('MQTT downlink topics: ' . $mqttBridge->topic('devices/{imei}/downlink'));

$loop->run();
