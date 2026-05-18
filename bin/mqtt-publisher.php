#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Bootstrap;
use App\Log\Logger;
use App\Mqtt\PayloadBuilder;
use App\Mqtt\SimpleClient;
use App\Registry\Whitelist;

$config = Bootstrap::config();
$mqttConfig = $config['mqtt'] ?? [];

$redis = Bootstrap::requireRedis($config['redis'] ?? []);

$pdo = Bootstrap::database($config['database'] ?? null);
Bootstrap::setupDeviceCapabilities($pdo);
$whitelist = new Whitelist(pdo: $pdo);

$mqttHost = trim((string)($mqttConfig['host'] ?? ''));
$mqttPort = (int)($mqttConfig['port'] ?? 1883);
$mqttUser = (string)($mqttConfig['username'] ?? '');
$mqttPass = (string)($mqttConfig['password'] ?? '');
$mqttPrefix = trim((string)($mqttConfig['topic_prefix'] ?? ''), '/');
$clientIdPrefix = preg_replace('/[^a-zA-Z0-9_-]/', '-', (string)($mqttConfig['client_id_prefix'] ?? 'health-mqtt')) ?: 'health-mqtt';
$clientId = substr($clientIdPrefix . '-' . getmypid(), 0, 23);
$keepAlive = (int)($mqttConfig['keepalive'] ?? 60);
$timeout = (float)($mqttConfig['timeout'] ?? 5.0);
$tlsEnabled = (bool)($mqttConfig['tls_enabled'] ?? false);
$tlsVerifyPeer = (bool)($mqttConfig['tls_verify_peer'] ?? true);
$tlsCaFile = (string)($mqttConfig['tls_ca_file'] ?? '');
$tlsCertFile = (string)($mqttConfig['tls_cert_file'] ?? '');
$tlsKeyFile = (string)($mqttConfig['tls_key_file'] ?? '');

if ($mqttHost === '') {
    Logger::channel('mqtt-publisher')->error('MQTT_HOST is required');
    exit(1);
}

$topicForTelemetry = static function (string $imei) use ($mqttPrefix): string {
    $base = "devices/$imei/telemetry";
    return $mqttPrefix === '' ? $base : ($mqttPrefix . '/' . $base);
};
$topicForStatus = static function (string $imei) use ($mqttPrefix): string {
    $base = "devices/$imei/status";
    return $mqttPrefix === '' ? $base : ($mqttPrefix . '/' . $base);
};
$topicForError = static function (string $imei) use ($mqttPrefix): string {
    $base = "devices/$imei/error";
    return $mqttPrefix === '' ? $base : ($mqttPrefix . '/' . $base);
};
$topicForCommandState = static function (string $imei) use ($mqttPrefix): string {
    $base = "devices/$imei/command/state";
    return $mqttPrefix === '' ? $base : ($mqttPrefix . '/' . $base);
};

$telemetryCursorPath = __DIR__ . '/../var/mqtt-publisher.telemetry.cursor';
$statusCursorPath = __DIR__ . '/../var/mqtt-publisher.status.cursor';
$errorCursorPath = __DIR__ . '/../var/mqtt-publisher.error.cursor';
$commandStateCursorPath = __DIR__ . '/../var/mqtt-publisher.command-state.cursor';
$telemetryCursor = loadCursor($telemetryCursorPath) ?: '0-0';
$statusCursor = loadCursor($statusCursorPath) ?: '0-0';
$errorCursor = loadCursor($errorCursorPath) ?: '0-0';
$commandStateCursor = loadCursor($commandStateCursorPath) ?: '0-0';

$mqtt = new SimpleClient(
    host: $mqttHost,
    port: $mqttPort,
    clientId: $clientId,
    username: $mqttUser,
    password: $mqttPass,
    keepAlive: $keepAlive,
    timeout: $timeout,
    tlsEnabled: $tlsEnabled,
    tlsVerifyPeer: $tlsVerifyPeer,
    tlsCaFile: $tlsCaFile,
    tlsCertFile: $tlsCertFile,
    tlsKeyFile: $tlsKeyFile,
);

Logger::channel('mqtt-publisher')->info('Starting MQTT publisher');
Logger::channel('mqtt-publisher')->info("Redis streams: events(cursor={$telemetryCursor}), status(cursor={$statusCursor}), errors(cursor={$errorCursor}), command_state(cursor={$commandStateCursor})");
Logger::channel('mqtt-publisher')->info(
    "Broker: {$mqttHost}:{$mqttPort}, topic patterns: " .
    ($mqttPrefix === '' ? 'devices/{imei}/telemetry|status|error|command/state' : $mqttPrefix . '/devices/{imei}/...')
);
if ($tlsEnabled) {
    Logger::channel('mqtt-publisher')->info('MQTT TLS is enabled');
}

$running = true;
if (extension_loaded('pcntl')) {
    pcntl_signal(SIGINT, function () use (&$running): void {
        $running = false;
    });
    pcntl_signal(SIGTERM, function () use (&$running): void {
        $running = false;
    });
}

while ($running) {
    if (extension_loaded('pcntl')) {
        pcntl_signal_dispatch();
    }

    try {
        $statusEvents = $redis->readStatus($statusCursor, 50);
    } catch (\Throwable $e) {
        Logger::channel('mqtt-publisher')->error('readStatus failed: ' . $e->getMessage());
        usleep(500000);
        continue;
    }

    foreach ($statusEvents as $statusEvent) {
        $imei = (string)($statusEvent['imei'] ?? '');
        if ($imei === '') {
            $statusCursor = (string)($statusEvent['streamId'] ?? $statusCursor);
            persistCursor($statusCursorPath, $statusCursor);
            continue;
        }

        $topic = $topicForStatus($imei);
        $payload = PayloadBuilder::buildStatusPayload($statusEvent, $imei, $whitelist);

        try {
            $mqtt->publish($topic, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), retain: true);
            $statusCursor = (string)$statusEvent['streamId'];
            persistCursor($statusCursorPath, $statusCursor);
        } catch (\Throwable $e) {
            Logger::channel('mqtt-publisher')->error("Status publish failed for IMEI={$imei}: " . $e->getMessage());
            usleep(500000);
            continue 2;
        }
    }

    try {
        $errorEvents = $redis->readErrors($errorCursor, 50);
    } catch (\Throwable $e) {
        Logger::channel('mqtt-publisher')->error('readErrors failed: ' . $e->getMessage());
        usleep(500000);
        continue;
    }

    foreach ($errorEvents as $errorEvent) {
        $imei = (string)($errorEvent['imei'] ?? '');
        if ($imei === '') {
            $errorCursor = (string)($errorEvent['streamId'] ?? $errorCursor);
            persistCursor($errorCursorPath, $errorCursor);
            continue;
        }

        $topic = $topicForError($imei);
        $payload = PayloadBuilder::buildErrorPayload($errorEvent, $imei, $whitelist);

        try {
            $mqtt->publish($topic, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $errorCursor = (string)$errorEvent['streamId'];
            persistCursor($errorCursorPath, $errorCursor);
        } catch (\Throwable $e) {
            Logger::channel('mqtt-publisher')->error("Error publish failed for IMEI={$imei}: " . $e->getMessage());
            usleep(500000);
            continue 2;
        }
    }

    try {
        $commandStateEvents = $redis->readCommandState($commandStateCursor, 50);
    } catch (\Throwable $e) {
        Logger::channel('mqtt-publisher')->error('readCommandState failed: ' . $e->getMessage());
        usleep(500000);
        continue;
    }

    foreach ($commandStateEvents as $commandStateEvent) {
        $imei = (string)($commandStateEvent['imei'] ?? '');
        if ($imei === '') {
            $commandStateCursor = (string)($commandStateEvent['streamId'] ?? $commandStateCursor);
            persistCursor($commandStateCursorPath, $commandStateCursor);
            continue;
        }

        $topic = $topicForCommandState($imei);
        $payload = PayloadBuilder::buildCommandStatePayload($commandStateEvent, $imei, $whitelist);

        try {
            $mqtt->publish($topic, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $commandStateCursor = (string)$commandStateEvent['streamId'];
            persistCursor($commandStateCursorPath, $commandStateCursor);
        } catch (\Throwable $e) {
            Logger::channel('mqtt-publisher')->error("Command-state publish failed for IMEI={$imei}: " . $e->getMessage());
            usleep(500000);
            continue 2;
        }
    }

    try {
        $events = $redis->readEvents($telemetryCursor, 50);
    } catch (\Throwable $e) {
        Logger::channel('mqtt-publisher')->error('readEvents failed: ' . $e->getMessage());
        usleep(500000);
        continue;
    }

    if ($events === [] && $statusEvents === [] && $errorEvents === [] && $commandStateEvents === []) {
        usleep(300000);
        continue;
    }

    foreach ($events as $event) {
        $imei = (string)($event['imei'] ?? '');
        if ($imei === '') {
            $telemetryCursor = (string)($event['streamId'] ?? $telemetryCursor);
            persistCursor($telemetryCursorPath, $telemetryCursor);
            continue;
        }

        $topic = $topicForTelemetry($imei);
        $payload = PayloadBuilder::buildTelemetryPayload($event, $imei, $whitelist);

        try {
            $mqtt->publish($topic, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $telemetryCursor = (string)$event['streamId'];
            persistCursor($telemetryCursorPath, $telemetryCursor);
        } catch (\Throwable $e) {
            Logger::channel('mqtt-publisher')->error("Telemetry publish failed for IMEI={$imei}: " . $e->getMessage());
            usleep(500000);
            continue 2;
        }
    }
}

$mqtt->disconnect();
Logger::channel('mqtt-publisher')->info('Stopped MQTT publisher');

function loadCursor(string $path): ?string
{
    if (!file_exists($path)) {
        return null;
    }

    $value = trim((string)file_get_contents($path));
    if ($value === '') {
        return null;
    }

    return $value;
}

function persistCursor(string $path, string $cursor): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    file_put_contents($path, $cursor . PHP_EOL);
}
