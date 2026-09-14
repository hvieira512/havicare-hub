#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Hub\Device\HubTcpIngress;
use Hub\Ingress\Mqtt\MqttIngressFactory;
use Hub\Log\Logger;
use Hub\Mqtt\BrokerSettings;
use Hub\Mqtt\ConnectionFactory;
use Hub\Runtime\CliBootstrap;
use Hub\Runtime\CrashWatch;
use Hub\Runtime\DashboardServerFactory;
use Hub\Runtime\HubServices;
use Hub\Runtime\MaintenanceScheduler;
use Hub\Runtime\StartupBanner;
use Hub\Runtime\SystemdWatchdog;
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

CrashWatch::attach($loop, $services, __DIR__ . '/../var/run/hub-boot.marker');

$runner = MqttIngressFactory::build($config, $services, $hubConnections, $loop);

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

// Conduz o loop de cada ingestão, e drena a fila das que têm o que entregar -- as pulseiras
// servidas por gateway, cujo comando não pode esperar pelo anúncio de sessão seguinte.
$runner->scheduleTicks();
MaintenanceScheduler::schedule($loop, $services, $config['dashboard']);

// Drena os PUBACK do publicador MQTT. Cada `status`/`event` QoS 1 fica pendente até ser
// confirmado; sem alguém a correr o loop do publicador, a fila enche até o cliente rebentar
// e perder mensagens. Um segundo chega para a manter drenada ao ritmo real de publicação.
$loop->addPeriodicTimer(1.0, static function () use ($services): void {
    try {
        $services->mqttBridge->drainPublisher();
    } catch (\Throwable $e) {
        Logger::channel('hub')->error('MQTT publisher drain failed: ' . $e->getMessage());
    }
});

// O sinal de vida para o systemd, que sai de um temporizador deste loop e por isso só é
// enviado enquanto ele girar. Fora do systemd devolve `null` e não faz nada.
$watchdog = SystemdWatchdog::fromEnvironment();
$watchdog?->attach($loop);

StartupBanner::log($config, $services->mqttBridge, $runner->keys(), $watchdog);

$loop->run();
