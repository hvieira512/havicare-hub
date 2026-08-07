#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Hub\HubDownlinkSubscriber;
use Hub\HubTcpIngress;
use Hub\Ingress\Mqtt\IngressRunner;
use Hub\Ingress\Mqtt\Moko\Bridge as MokoBridge;
use Hub\Ingress\Mqtt\Moko\RedisObservationStateStore;
use Hub\Ingress\Mqtt\Ncs\Bridge as NcsBridge;
use Hub\Ingress\Mqtt\Qinglanst\Bridge as QinglanstBridge;
use Hub\Ingress\Mqtt\Qinglanst\DashboardWritePolicy as QinglanstDashboardWritePolicy;
use Hub\Ingress\Mqtt\SubscriberFactory;
use Hub\Log\Logger;
use Hub\Mqtt\BrokerSettings;
use Hub\Mqtt\ConnectionFactory;
use Hub\Runtime\CliBootstrap;
use Hub\Runtime\DashboardServerFactory;
use Hub\Runtime\HubServices;
use Hub\Runtime\MaintenanceScheduler;
use Hub\Runtime\StartupBanner;
use React\EventLoop\Loop;

try {
    $config = CliBootstrap::config(__DIR__ . '/..', validate: true);
    $hubConnections = new ConnectionFactory(BrokerSettings::fromHubConfig($config['mqtt']));
} catch (\Throwable $e) {
    Logger::channel('hub')->error($e->getMessage());
    exit(1);
}

$services = HubServices::boot($config, $hubConnections);
$loop = Loop::get();
$subscribers = new SubscriberFactory($hubConnections);
$runner = new IngressRunner($loop);
$enabledIngresses = [];

$runner->add('MQTT downlink', $subscribers->bind(
    'sub',
    $services->mqttBridge->downlinkTopicFilter(),
    fn ($subscriber, $reconnect) => new HubDownlinkSubscriber(
        $subscriber,
        $services->hubServer,
        trim((string)$config['mqtt']['topic_prefix'], '/'),
        $reconnect,
    ),
));

if ($config['ncs']['enabled']) {
    $ncsTopicFilter = trim((string)$config['ncs']['topic_filter']);
    $runner->add('NCS ingress', $subscribers->bind(
        'ncs-sub',
        $ncsTopicFilter,
        fn ($subscriber, $reconnect) => new NcsBridge(
            $subscriber,
            $services->whitelist,
            $services->mqttBridge,
            $ncsTopicFilter,
            $reconnect,
            $services->dashboardStore,
            commercialModelResolver: $services->commercialModelResolver,
        ),
    ));
    $enabledIngresses[] = 'ncs';
}

if ($config['moko']['enabled']) {
    $mokoTopicFilter = trim((string)$config['moko']['topic_filter']);
    $runner->add('MOKO gateway ingress', $subscribers->bind(
        'moko-sub',
        $mokoTopicFilter,
        fn ($subscriber, $reconnect) => new MokoBridge(
            $subscriber,
            $services->whitelist,
            $services->mqttBridge,
            $services->dataAccess->gatewayDeviceLinks,
            new RedisObservationStateStore($services->redis),
            $mokoTopicFilter,
            $reconnect,
            $services->dashboardStore,
            $services->commercialModelResolver,
            (int)$config['moko']['dedupe_ttl_seconds'],
            (int)$config['moko']['telemetry_refresh_seconds'],
            (int)$config['moko']['idle_timeout_seconds'],
        ),
    ));
    $enabledIngresses[] = 'moko';
}

if ($config['qinglanst']['enabled']) {
    $qinglanstTopicFilter = trim((string)$config['qinglanst']['topic_filter']);
    $qinglanstSubscribers = new SubscriberFactory(
        new ConnectionFactory(BrokerSettings::fromQinglanstConfig($config['qinglanst'])),
        stableClientId: false,
    );
    $runner->add('Qinglanst ingress', $qinglanstSubscribers->bind(
        'sub',
        $qinglanstTopicFilter,
        fn ($subscriber, $reconnect) => new QinglanstBridge(
            $subscriber,
            $services->whitelist,
            $services->mqttBridge,
            $qinglanstTopicFilter,
            $reconnect,
            $services->dashboardStore,
            dashboardWritePolicy: new QinglanstDashboardWritePolicy(
                (int)$config['qinglanst']['dashboard_seen_min_interval_ms'],
                (int)$config['qinglanst']['position_history_sample_ms'],
            ),
            commercialModelResolver: $services->commercialModelResolver,
        ),
    ));
    $enabledIngresses[] = 'qinglanst';
}

new HubTcpIngress(
    $services->hubServer,
    $loop,
    $config['tcp_ingress']['host'],
    $config['tcp_ingress']['port'],
);
DashboardServerFactory::listen($services, $config['dashboard'], $loop);

try {
    $runner->start();
} catch (\Throwable $e) {
    Logger::channel('hub')->error($e->getMessage());
    exit(1);
}

$runner->scheduleTicks();
MaintenanceScheduler::schedule($loop, $services, $config['dashboard']);
StartupBanner::log($config, $services->mqttBridge, $enabledIngresses);

$loop->run();
