#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Hub\Bootstrap;
use Hub\Config;
use Hub\Configuration\HubConfigurationValidator;
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
use Hub\Ingress\Mqtt\Moko\Bridge as MokoBridge;
use Hub\Ingress\Mqtt\Moko\RedisObservationStateStore;
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
(new HubConfigurationValidator())->validate($config);
$mqttConfig = $config['mqtt'];
$redisConfig = $config['redis'];
$databaseConfig = $config['database'];
$dashboardConfig = $config['dashboard'];
$ncsConfig = $config['ncs'];
$mokoConfig = $config['moko'];
$qinglanstConfig = $config['qinglanst'];
$locationResolutionConfig = $config['location_resolution'];
$downlinkQueueTtlSeconds = $config['hub']['downlink_queue_ttl_seconds'];

$mqttHost = trim($mqttConfig['host']);
if ($mqttHost === '') {
    Logger::channel('hub')->error('MQTT_HOST is required for the devices hub');
    exit(1);
}

$clientIdPrefix = preg_replace('/[^a-zA-Z0-9_-]/', '-', $mqttConfig['client_id_prefix']) ?: 'havicare-hub';
$topicPrefix = trim($mqttConfig['topic_prefix'], '/');

$createMqttClient = static function (string $suffix, bool $stableClientId = false, ?Repository $repository = null) use ($mqttConfig, $mqttHost, $clientIdPrefix): MqttClient {
    $clientId = $stableClientId
        ? substr($clientIdPrefix . '-' . $suffix, 0, 23)
        : substr($clientIdPrefix . '-' . $suffix . '-' . getmypid(), 0, 23);

    return new MqttClient($mqttHost, $mqttConfig['port'], $clientId, MqttClient::MQTT_3_1_1, $repository);
};

$connectMqttClient = static function (MqttClient $client, bool $cleanSession = true) use ($mqttConfig): MqttClient {
    $username = $mqttConfig['username'];
    $password = $mqttConfig['password'];
    $timeout = max(1, (int)ceil($mqttConfig['timeout']));

    $settings = (new ConnectionSettings())
        ->setUsername($username !== '' ? $username : null)
        ->setPassword($password !== '' ? $password : null)
        ->setKeepAliveInterval(max(1, $mqttConfig['keepalive']))
        ->setConnectTimeout($timeout)
        ->setSocketTimeout($timeout)
        ->setUseTls($mqttConfig['tls_enabled'])
        ->setTlsVerifyPeer($mqttConfig['tls_verify_peer'])
        ->setTlsVerifyPeerName($mqttConfig['tls_verify_peer'])
        ->setTlsSelfSignedAllowed(!$mqttConfig['tls_verify_peer'])
        ->setTlsCertificateAuthorityFile($mqttConfig['tls_ca_file'] !== '' ? $mqttConfig['tls_ca_file'] : null)
        ->setTlsClientCertificateFile($mqttConfig['tls_cert_file'] !== '' ? $mqttConfig['tls_cert_file'] : null)
        ->setTlsClientCertificateKeyFile($mqttConfig['tls_key_file'] !== '' ? $mqttConfig['tls_key_file'] : null);

    $client->connect($settings, $cleanSession);

    return $client;
};

$buildMqttClient = static fn (string $suffix, bool $cleanSession = true, bool $stableClientId = false): MqttClient
    => $connectMqttClient($createMqttClient($suffix, $stableClientId), $cleanSession);

$database = new Hub\Infrastructure\Persistence\DashboardDatabase($databaseConfig);
(new Hub\Infrastructure\Persistence\DatabaseSchemaGuard($database->pdo()))->assertCurrent();
$dataAccess = Hub\Api\Repository\ApiDataAccess::fromDatabase($database);
$whitelistFile = trim($config['hub']['whitelist_file']);
$whitelist = new Whitelist($whitelistFile !== '' ? $whitelistFile : null, $dataAccess->whitelist);
$redisParameters = [
    'host' => $redisConfig['host'],
    'port' => $redisConfig['port'],
];
$redisPassword = $redisConfig['password'];
if ($redisPassword !== '') {
    $redisParameters['password'] = $redisPassword;
}
$downlinkQueue = new RedisPendingDownlinkQueue(new RedisClient($redisParameters));
$dashboardStore = new DashboardStore(new RedisClient($redisParameters), $dashboardConfig['history_limit']);
$dashboardStore->setDataAccess($dataAccess);
$commercialModelResolver = new Hub\CommercialModelResolver($dataAccess->models);
$mqttBridge = new HubMqttBridge(
    $buildMqttClient('pub'),
    $topicPrefix,
    static fn (): MqttClient => $buildMqttClient('pub')
);
$locationTelemetryEnricher = null;
if ($locationResolutionConfig['enabled']) {
    $locationRedis = new RedisClient($redisParameters);
    $beaconDbClient = new Hub\Location\BeaconDbAsyncClient(
        new Browser(),
        $locationResolutionConfig['endpoint'],
        $locationResolutionConfig['user_agent'],
        $locationResolutionConfig['timeout_seconds'],
    );
    $locationProvider = new Hub\Location\ConcurrentLocationProvider(
        new Hub\Location\CircuitBreakingLocationProvider(
            $beaconDbClient,
            new Hub\Location\RedisProviderCircuitStateStore($locationRedis),
            $locationResolutionConfig['circuit_failure_threshold'],
            $locationResolutionConfig['circuit_open_seconds'],
            $locationResolutionConfig['rate_limit_open_seconds'],
        ),
        $locationResolutionConfig['max_concurrency'],
        $locationResolutionConfig['max_queue'],
    );
    $locationCache = new Hub\Location\TieredLocationResolutionCache(
        new Hub\Location\ArrayLocationResolutionCache(),
        new Hub\Location\RedisLocationResolutionCache($locationRedis),
    );
    $requestBuilder = new Hub\Location\BeaconDbRequestBuilder();
    $locationTelemetryEnricher = new Hub\Location\BeaconDbTelemetryEnricher(
        $requestBuilder,
        $locationProvider,
        $locationResolutionConfig['max_accuracy_meters'],
        $locationResolutionConfig['cache_ttl_seconds'],
        $locationResolutionConfig['failure_cache_ttl_seconds'],
        $locationCache,
    );
    if ($locationResolutionConfig['radio_map_enabled']) {
        $radioMapHashKey = trim($locationResolutionConfig['radio_map_hash_key']);
        if ($radioMapHashKey === '') {
            Logger::channel('hub')->error('Private radio map disabled because RADIO_MAP_HASH_KEY is empty');
        } else {
            $privateRadioMap = Hub\Location\PrivateRadioMapFactory::create(
                $database->pdo(),
                $locationResolutionConfig,
                $requestBuilder,
            );
            $locationTelemetryEnricher = new Hub\Location\PrivateRadioMapTelemetryEnricher(
                $privateRadioMap,
                $locationTelemetryEnricher,
            );
        }
    }
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
$mokoIngress = null;
$downlinkTopicFilter = $mqttBridge->downlinkTopicFilter();
$ncsTopicFilter = trim($ncsConfig['topic_filter']);
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
if ($ncsConfig['enabled']) {
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

$mokoTopicFilter = trim($mokoConfig['topic_filter']);
$mokoSubscriber = null;
if ($mokoConfig['enabled']) {
    $mokoSubscriberRepository = new MemoryRepository();
    $mokoSubscriberRepository->addSubscription(new Subscription(
        $mokoTopicFilter,
        MqttClient::QOS_AT_LEAST_ONCE,
        static function (string $topic, string $message) use (&$mokoIngress): void {
            $mokoIngress?->handleReceivedMessage($topic, $message);
        }
    ));
    $mokoSubscriber = $createMqttClient('moko-sub', true, $mokoSubscriberRepository);
    $mokoIngress = new MokoBridge(
        $mokoSubscriber,
        $whitelist,
        $mqttBridge,
        $dataAccess->gatewayDeviceLinks,
        new RedisObservationStateStore(new RedisClient($redisParameters)),
        $mokoTopicFilter,
        function () use (&$mokoIngress, $createMqttClient, $connectMqttClient, $mokoTopicFilter): MqttClient {
            $repository = new MemoryRepository();
            $repository->addSubscription(new Subscription(
                $mokoTopicFilter,
                MqttClient::QOS_AT_LEAST_ONCE,
                static function (string $topic, string $message) use (&$mokoIngress): void {
                    $mokoIngress?->handleReceivedMessage($topic, $message);
                }
            ));
            return $connectMqttClient($createMqttClient('moko-sub', true, $repository), false);
        },
        $dashboardStore,
        $commercialModelResolver,
        $mokoConfig['dedupe_ttl_seconds'],
        $mokoConfig['telemetry_refresh_seconds'],
        $mokoConfig['idle_timeout_seconds'],
    );
    $connectMqttClient($mokoSubscriber, false);
}

$qinglanstIngress = null;
if ($qinglanstConfig['enabled']) {
    $qinglanstHost = trim($qinglanstConfig['host']);
    if ($qinglanstHost !== '') {
        $qinglanstPort = $qinglanstConfig['port'];
        $qinglanstUsername = trim($qinglanstConfig['username']);
        $qinglanstPassword = trim($qinglanstConfig['password']);
        $qinglanstTopicFilter = trim($qinglanstConfig['topic_filter']);
        $qinglanstClientIdPrefix = preg_replace('/[^a-zA-Z0-9_-]/', '-', $qinglanstConfig['client_id_prefix']) ?: 'qinglanst-radar';
        $qinglanstDashboardWritePolicy = new QinglanstDashboardWritePolicy(
            $qinglanstConfig['dashboard_seen_min_interval_ms'],
            $qinglanstConfig['position_history_sample_ms'],
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
$tcpHost = $config['tcp_ingress']['host'];
$tcpPort = $config['tcp_ingress']['port'];
$dashboardHost = $dashboardConfig['host'];
$dashboardPort = $dashboardConfig['port'];

$tcpIngress = new HubTcpIngress($hubServer, $loop, $tcpHost, $tcpPort);

$dashboard = new DashboardHttpServer(
        $dashboardStore,
        new Hub\Api\Auth\ApiTokenStore(new RedisClient($redisParameters)),
        $whitelist,
        $hubServer,
        $downlinkQueue,
        $dataAccess,
        $dashboardConfig['api_auth_required'],
        $dashboardConfig['api_token_ttl_seconds'],
        $dashboardConfig['api_refresh_token_ttl_seconds']
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

if ($mokoIngress !== null) {
    try {
        $mokoIngress->start();
    } catch (\Throwable $e) {
        Logger::channel('hub')->error('MOKO gateway ingress subscription failed: ' . $e->getMessage());
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

if ($mokoIngress !== null) {
    $loop->addPeriodicTimer(0.05, function () use ($mokoIngress): void {
        try {
            $mokoIngress->tick(0.001);
        } catch (\Throwable $e) {
            Logger::channel('hub')->error('MOKO gateway ingress loop failed: ' . $e->getMessage());
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
        $dashboardConfig['command_timeout_seconds'],
        3,
        static fn(string $imei, string $bytes, array $command): string => $hubServer->submitDownlink($imei, $bytes)
    );
    $dashboardStore->expireWaitingCommands($dashboardConfig['command_timeout_seconds']);
    $dashboardStore->expireStaleDevices($dashboardConfig['device_idle_timeout_seconds']);
});
$loop->addPeriodicTimer(10, function () use ($hubServer, $dashboardConfig): void {
    $hubServer->expireIdleConnections($dashboardConfig['device_idle_timeout_seconds']);
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
if ($mokoIngress !== null) {
    Logger::channel('hub')->info("MOKO MKGW3 ingress topics: {$mokoTopicFilter} -> " . $mqttBridge->topic('{company}/{licenseId}/gateway/{gatewayMac}/{raw|status|events|telemetry}'));
}
if ($qinglanstIngress !== null) {
    Logger::channel('hub')->info("Qinglanst radar ingress: {$qinglanstTopicFilter} -> " . $mqttBridge->topic('{company}/{licenseId}/radar/{deviceUid}/{telemetry|events}'));
}

$loop->run();
