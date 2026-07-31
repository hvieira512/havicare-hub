#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Hub\Bootstrap;
use Hub\Config;
use Hub\Api\Auth\ApiTokenStore;
use Hub\Dashboard\DashboardHttpServer;
use Hub\Dashboard\DashboardStore;
use Hub\DeviceHubServer;
use Hub\HubDownlinkSubscriber;
use Hub\HubMqttBridge;
use Hub\HubTcpIngress;
use Hub\Ingress\Mqtt\Qinglanst\Bridge as QinglanstBridge;
use Hub\Ingress\Mqtt\Qinglanst\DashboardWritePolicy as QinglanstDashboardWritePolicy;
use Hub\Ingress\Mqtt\Ncs\Bridge as NcsBridge;
use Hub\RedisPendingDownlinkQueue;
use Hub\Log\Logger;
use Hub\Registry\Whitelist;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\Contracts\Repository;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\Repositories\MemoryRepository;
use PhpMqtt\Client\Subscription;
use Predis\Client as RedisClient;
use React\Http\Middleware\LimitConcurrentRequestsMiddleware;
use React\Http\Middleware\RequestBodyBufferMiddleware;
use React\Http\Middleware\RequestBodyParserMiddleware;
use React\Http\Middleware\StreamingRequestMiddleware;
use React\EventLoop\Loop;
use React\Http\HttpServer as ReactHttpServer;
use React\Http\Browser;
use React\Socket\SocketServer;

Bootstrap::loadEnv(__DIR__ . '/..');

$config = Config::load()->all();
$mqttConfig = $config['mqtt'] ?? [];
$redisConfig = $config['redis'] ?? [];
$databaseConfig = $config['database'] ?? [];
$dashboardConfig = $config['dashboard'] ?? [];
$ncsConfig = $config['ncs'] ?? [];
$qinglanstConfig = $config['qinglanst'] ?? [];
$locationResolutionConfig = $config['location_resolution'] ?? [];
$downlinkQueueTtlSeconds = (int)($config['hub']['downlink_queue_ttl_seconds'] ?? 300);

$mqttHost = trim((string)($mqttConfig['host'] ?? ''));
if ($mqttHost === '') {
    Logger::channel('hub')->error('MQTT_HOST is required for the devices hub');
    exit(1);
}

$clientIdPrefix = preg_replace('/[^a-zA-Z0-9_-]/', '-', (string)($mqttConfig['client_id_prefix'] ?? 'havicare-hub')) ?: 'havicare-hub';
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

$database = new Hub\Infrastructure\Persistence\DashboardDatabase($databaseConfig);
$dataAccess = Hub\Api\Repository\ApiDataAccess::fromDatabase($database);
$whitelistFile = trim((string)($config['hub']['whitelist_file'] ?? ''));
$whitelist = new Whitelist($whitelistFile !== '' ? $whitelistFile : null, $dataAccess->whitelist);
$redisParameters = [
    'host' => (string)($redisConfig['host'] ?? '127.0.0.1'),
    'port' => (int)($redisConfig['port'] ?? 6379),
];
$redisPassword = (string)($redisConfig['password'] ?? '');
if ($redisPassword !== '') {
    $redisParameters['password'] = $redisPassword;
}
$downlinkQueue = new RedisPendingDownlinkQueue(new RedisClient($redisParameters));
$dashboardStore = new DashboardStore(new RedisClient($redisParameters), (int)($dashboardConfig['history_limit'] ?? 100));
$dashboardStore->setDataAccess($dataAccess);
$commercialModelResolver = new Hub\CommercialModelResolver($dataAccess->models);
$mqttBridge = new HubMqttBridge(
    $buildMqttClient('pub'),
    $topicPrefix,
    static fn (): MqttClient => $buildMqttClient('pub')
);
$locationTelemetryEnricher = null;
if ((bool)($locationResolutionConfig['enabled'] ?? true)) {
    $beaconDbClient = new Hub\Location\BeaconDbAsyncClient(
        new Browser(),
        (string)($locationResolutionConfig['endpoint'] ?? 'https://api.beacondb.net/v1/geolocate'),
        (string)($locationResolutionConfig['user_agent'] ?? 'HaviCare Devices Hub/1.0'),
        (float)($locationResolutionConfig['timeout_seconds'] ?? 5.0),
    );
    $locationTelemetryEnricher = new Hub\Location\BeaconDbTelemetryEnricher(
        new Hub\Location\BeaconDbRequestBuilder(),
        $beaconDbClient->resolve(...),
        (float)($locationResolutionConfig['max_accuracy_meters'] ?? 500.0),
        (int)($locationResolutionConfig['cache_ttl_seconds'] ?? 300),
        (int)($locationResolutionConfig['failure_cache_ttl_seconds'] ?? 60),
    );
}
$hubServer = new DeviceHubServer(
    $whitelist,
    $mqttBridge,
    $commercialModelResolver,
    downlinkQueue: $downlinkQueue,
    dashboardStore: $dashboardStore,
    downlinkQueueTtlSeconds: $downlinkQueueTtlSeconds,
    locationTelemetryEnricher: $locationTelemetryEnricher,
);
$downlink = null;
$ncsIngress = null;
$downlinkTopicFilter = $mqttBridge->downlinkTopicFilter();
$ncsTopicFilter = trim((string)($ncsConfig['topic_filter'] ?? '/voerka/#'));
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

$ncsSubscriber = null;
if ((bool)($ncsConfig['enabled'] ?? true)) {
    $ncsSubscriberRepository = new MemoryRepository();
    $ncsSubscriberRepository->addSubscription(new Subscription(
        $ncsTopicFilter,
        MqttClient::QOS_AT_LEAST_ONCE,
        static function (string $topic, string $message) use (&$ncsIngress): void {
            $ncsIngress?->handleReceivedMessage($topic, $message);
        }
    ));
    $ncsSubscriber = $createMqttClient('ncs-sub', true, $ncsSubscriberRepository);
    $ncsIngress = new NcsBridge(
        $ncsSubscriber,
        $whitelist,
        $mqttBridge,
        $ncsTopicFilter,
        function () use (&$ncsIngress, $createMqttClient, $connectMqttClient, $ncsTopicFilter): MqttClient {
            $repository = new MemoryRepository();
            $repository->addSubscription(new Subscription(
                $ncsTopicFilter,
                MqttClient::QOS_AT_LEAST_ONCE,
                static function (string $topic, string $message) use (&$ncsIngress): void {
                    $ncsIngress?->handleReceivedMessage($topic, $message);
                }
            ));

            return $connectMqttClient($createMqttClient('ncs-sub', true, $repository), false);
        },
        $dashboardStore,
        commercialModelResolver: $commercialModelResolver
    );
    $connectMqttClient($ncsSubscriber, false);
}

$qinglanstIngress = null;
if ((bool)($qinglanstConfig['enabled'] ?? true)) {
    $qinglanstHost = trim((string)($qinglanstConfig['host'] ?? ''));
    if ($qinglanstHost !== '') {
        $qinglanstPort = (int)($qinglanstConfig['port'] ?? 1883);
        $qinglanstUsername = trim((string)($qinglanstConfig['username'] ?? ''));
        $qinglanstPassword = trim((string)($qinglanstConfig['password'] ?? ''));
        $qinglanstTopicFilter = trim((string)($qinglanstConfig['topic_filter'] ?? 'radar/1001/#'));
        $qinglanstClientIdPrefix = preg_replace('/[^a-zA-Z0-9_-]/', '-', (string)($qinglanstConfig['client_id_prefix'] ?? 'qinglanst-radar')) ?: 'qinglanst-radar';
        $qinglanstDashboardWritePolicy = new QinglanstDashboardWritePolicy(
            (int)($qinglanstConfig['dashboard_seen_min_interval_ms'] ?? 5000),
            (int)($qinglanstConfig['position_history_sample_ms'] ?? 1000),
        );

        $createQinglanstClient = static function (string $suffix, ?Repository $repository = null) use ($qinglanstHost, $qinglanstPort, $qinglanstClientIdPrefix): MqttClient {
            $clientId = substr($qinglanstClientIdPrefix . '-' . $suffix . '-' . getmypid(), 0, 23);
            return new MqttClient($qinglanstHost, $qinglanstPort, $clientId, MqttClient::MQTT_3_1_1, $repository);
        };

        $connectQinglanstClient = static function (MqttClient $client, bool $cleanSession = true) use ($qinglanstUsername, $qinglanstPassword): MqttClient {
            $settings = (new ConnectionSettings())
                ->setUsername($qinglanstUsername !== '' ? $qinglanstUsername : null)
                ->setPassword($qinglanstPassword !== '' ? $qinglanstPassword : null)
                ->setKeepAliveInterval(60)
                ->setConnectTimeout(5)
                ->setSocketTimeout(5);
            $client->connect($settings, $cleanSession);
            return $client;
        };

        $qinglanstSubscriberRepository = new MemoryRepository();
        $qinglanstSubscriberRepository->addSubscription(new Subscription(
            $qinglanstTopicFilter,
            MqttClient::QOS_AT_LEAST_ONCE,
            static function (string $topic, string $message) use (&$qinglanstIngress): void {
                $qinglanstIngress?->handleReceivedMessage($topic, $message);
            }
        ));
        $qinglanstSubscriber = $createQinglanstClient('sub', $qinglanstSubscriberRepository);
        $qinglanstIngress = new QinglanstBridge(
            $qinglanstSubscriber,
            $whitelist,
            $mqttBridge,
            $qinglanstTopicFilter,
            function () use (&$qinglanstIngress, $createQinglanstClient, $connectQinglanstClient, $qinglanstTopicFilter): MqttClient {
                $repository = new MemoryRepository();
                $repository->addSubscription(new Subscription(
                    $qinglanstTopicFilter,
                    MqttClient::QOS_AT_LEAST_ONCE,
                    static function (string $topic, string $message) use (&$qinglanstIngress): void {
                        $qinglanstIngress?->handleReceivedMessage($topic, $message);
                    }
                ));
                return $connectQinglanstClient($createQinglanstClient('sub', $repository), false);
            },
            $dashboardStore,
            dashboardWritePolicy: $qinglanstDashboardWritePolicy,
            commercialModelResolver: $commercialModelResolver,
        );
        $connectQinglanstClient($qinglanstSubscriber, false);
    }
}

$loop = Loop::get();
$tcpHost = $config['tcp_ingress']['host'] ?? '0.0.0.0';
$tcpPort = $config['tcp_ingress']['port'] ?? 9000;
$dashboardHost = (string)($dashboardConfig['host'] ?? '0.0.0.0');
$dashboardPort = (int)($dashboardConfig['port'] ?? 8081);

$tcpIngress = new HubTcpIngress($hubServer, $loop, $tcpHost, $tcpPort);

$dashboard = new DashboardHttpServer(
        $dashboardStore,
        new Hub\Api\Auth\ApiTokenStore(new RedisClient($redisParameters)),
        $whitelist,
        $hubServer,
        $downlinkQueue,
        $dataAccess,
        (string)($dashboardConfig['username'] ?? ''),
        (string)($dashboardConfig['password'] ?? ''),
        (bool)($dashboardConfig['api_auth_required'] ?? true),
        (int)($dashboardConfig['api_token_ttl_seconds'] ?? 3600),
        (int)($dashboardConfig['api_refresh_token_ttl_seconds'] ?? 2592000)
    );
    $dashboardServer = new ReactHttpServer(
        new StreamingRequestMiddleware(),
        new LimitConcurrentRequestsMiddleware(50),
        new RequestBodyBufferMiddleware(6 * 1024 * 1024),
        new RequestBodyParserMiddleware(5 * 1024 * 1024),
        $dashboard
    );
$dashboardSocket = new SocketServer("$dashboardHost:$dashboardPort", [], $loop);
$dashboardServer->listen($dashboardSocket);

try {
    $downlink->start();
} catch (\Throwable $e) {
    Logger::channel('hub')->error('MQTT downlink subscription failed: ' . $e->getMessage());
    exit(1);
}

if ($ncsIngress !== null) {
    try {
        $ncsIngress->start();
    } catch (\Throwable $e) {
        Logger::channel('hub')->error('NCS ingress subscription failed: ' . $e->getMessage());
        exit(1);
    }
}

if ($qinglanstIngress !== null) {
    try {
        $qinglanstIngress->start();
    } catch (\Throwable $e) {
        Logger::channel('hub')->error('Qinglanst ingress subscription failed: ' . $e->getMessage());
        exit(1);
    }
}

$loop->addPeriodicTimer(0.05, function () use ($downlink): void {
    try {
        $downlink->tick(0.001);
    } catch (\Throwable $e) {
        Logger::channel('hub')->error('MQTT downlink loop failed: ' . $e->getMessage());
    }
});

if ($ncsIngress !== null) {
    $loop->addPeriodicTimer(0.05, function () use ($ncsIngress): void {
        try {
            $ncsIngress->tick(0.001);
        } catch (\Throwable $e) {
            Logger::channel('hub')->error('NCS ingress loop failed: ' . $e->getMessage());
        }
    });
}

if ($qinglanstIngress !== null) {
    $loop->addPeriodicTimer(0.05, function () use ($qinglanstIngress): void {
        try {
            $qinglanstIngress->tick(0.001);
        } catch (\Throwable $e) {
            Logger::channel('hub')->error('Qinglanst ingress loop failed: ' . $e->getMessage());
        }
    });
}

$loop->addPeriodicTimer(10, function () use ($dashboardStore, $dashboardConfig, $hubServer): void {
    $dashboardStore->retryWaitingCommands(
        60,
        (int)($dashboardConfig['command_timeout_seconds'] ?? 3600),
        3,
        static fn(string $imei, string $bytes, array $command): string => $hubServer->submitDownlink($imei, $bytes)
    );
    $dashboardStore->expireWaitingCommands((int)($dashboardConfig['command_timeout_seconds'] ?? 3600));
    $dashboardStore->expireStaleDevices((int)($dashboardConfig['device_idle_timeout_seconds'] ?? 1800));
});
$loop->addPeriodicTimer(10, function () use ($hubServer, $dashboardConfig): void {
    $hubServer->expireIdleConnections((int)($dashboardConfig['device_idle_timeout_seconds'] ?? 1800));
});

Logger::channel('hub')->info('=== Hitecosystem Devices Hub ===');

Logger::channel('hub')->info("Dashboard: http://$dashboardHost:$dashboardPort/dashboard");

Logger::channel('hub')->info("TCP ingress: tcp://$tcpHost:$tcpPort");
Logger::channel('hub')->info("Redis downlink queue: {$redisParameters['host']}:{$redisParameters['port']} ttl={$downlinkQueueTtlSeconds}s");
Logger::channel('hub')->info('MQTT status topics: ' . $mqttBridge->topic('{licenseId}/watch/{deviceKey}/status'));
Logger::channel('hub')->info('MQTT event topics: ' . $mqttBridge->topic('{licenseId}/watch/{deviceKey}/events'));
Logger::channel('hub')->info('MQTT raw topics: ' . $mqttBridge->topic('{licenseId}/watch/{deviceKey}/raw'));
Logger::channel('hub')->info('MQTT downlink topics: ' . $mqttBridge->topic('{licenseId}/watch/{deviceKey}/downlink'));
if ($ncsIngress !== null) {
    Logger::channel('hub')->info("NCS ingress topics: {$ncsTopicFilter} -> " . $mqttBridge->topic('{licenseId}/ncs/{deviceKey}/{raw|status|events|telemetry}'));
}
if ($qinglanstIngress !== null) {
    Logger::channel('hub')->info("Qinglanst radar ingress: {$qinglanstTopicFilter} -> " . $mqttBridge->topic('{company}/{licenseId}/radar/{deviceUid}/{telemetry|events}'));
}

$loop->run();
