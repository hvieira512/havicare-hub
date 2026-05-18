#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database\Database;
use App\Log\Logger;
use App\Mqtt\SimpleClient;
use App\Redis\Client as RedisClient;
use App\Registry\DeviceCapabilities;
use App\Registry\Whitelist;

$config = Config::load()->all();
$mqttConfig = $config['mqtt'] ?? [];
$redisConfig = $config['redis'] ?? [];
$dbConfig = $config['database'] ?? null;

if (!($mqttConfig['enabled'] ?? false)) {
    Logger::channel('mqtt-publisher')->warning('MQTT publisher is disabled. Set MQTT_ENABLED=true to run.');
    exit(0);
}

$redisHost = getenv('REDIS_HOST') ?: ($redisConfig['host'] ?? '');
if ($redisHost === '') {
    Logger::channel('mqtt-publisher')->error('Redis configuration is required');
    exit(1);
}

$redis = new RedisClient($redisConfig);
if (!$redis->isAvailable()) {
    Logger::channel('mqtt-publisher')->error('Redis is unavailable');
    exit(1);
}

$pdo = null;
if ($dbConfig && ($dbConfig['host'] ?? '') !== '' && ($dbConfig['name'] ?? '') !== '') {
    try {
        $pdo = Database::connect($dbConfig)->pdo();
    } catch (\PDOException $e) {
        Logger::channel('mqtt-publisher')->warning('MySQL unavailable (' . $e->getMessage() . '). Continuing without model metadata.');
    }
}

DeviceCapabilities::setDatabasePdo($pdo);
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
    Logger::channel('mqtt-publisher')->error('MQTT_HOST is required when MQTT publisher is enabled');
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
        $payload = buildStatusPayload($statusEvent, $imei, $whitelist);

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
        $payload = buildErrorPayload($errorEvent, $imei, $whitelist);

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
        $payload = buildCommandStatePayload($commandStateEvent, $imei, $whitelist);

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
        $payload = buildTelemetryPayload($event, $imei, $whitelist);

        try {
            $mqtt->publish($topic, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $telemetryCursor = (string)$event['streamId'];
            persistCursor($telemetryCursorPath, $telemetryCursor);
        } catch (\Throwable $e) {
            Logger::channel('mqtt-publisher')->error("Telemetry publish failed for IMEI={$imei}: " . $e->getMessage());
            // Stop processing current batch so message order is preserved.
            usleep(500000);
            continue 2;
        }
    }
}

$mqtt->disconnect();
Logger::channel('mqtt-publisher')->info('Stopped MQTT publisher');

function buildTelemetryPayload(array $event, string $imei, Whitelist $whitelist): array
{
    $streamId = (string)($event['streamId'] ?? '0-0');
    $receivedAtMs = (int)($event['receivedAt'] ?? (int)round(microtime(true) * 1000));

    $model = $whitelist->getModel($imei);
    $caps = $model ? DeviceCapabilities::forModel($model) : null;

    return [
        'schemaVersion' => '1.0',
        'eventType' => 'telemetry.received',
        'eventId' => eventIdFromStreamId($streamId),
        'occurredAt' => gmdate('Y-m-d\\TH:i:s\\Z', max(0, (int)floor($receivedAtMs / 1000))),
        'imei' => $imei,
        'model' => $model,
        'supplier' => $caps?->getSupplier(),
        'data' => [
            'feature' => ($event['feature'] ?? '') !== '' ? $event['feature'] : null,
            'nativeType' => $event['nativeType'] ?? null,
            'nativePayload' => $event['nativePayload'] ?? new stdClass(),
        ],
    ];
}

function buildStatusPayload(array $event, string $imei, Whitelist $whitelist): array
{
    $streamId = (string)($event['streamId'] ?? '0-0');
    $timestampMs = (int)($event['timestamp'] ?? (int)round(microtime(true) * 1000));
    $state = (string)($event['state'] ?? 'unknown');

    $model = $whitelist->getModel($imei);
    $caps = $model ? DeviceCapabilities::forModel($model) : null;

    return [
        'schemaVersion' => '1.0',
        'eventType' => 'device.status.changed',
        'eventId' => eventIdFromStreamId($streamId),
        'occurredAt' => gmdate('Y-m-d\\TH:i:s\\Z', max(0, (int)floor($timestampMs / 1000))),
        'imei' => $imei,
        'model' => $model,
        'supplier' => $caps?->getSupplier(),
        'data' => [
            'state' => $state,
            'reason' => $event['reason'] ?? null,
            'protocol' => $event['protocol'] ?? null,
        ],
    ];
}

function buildErrorPayload(array $event, string $imei, Whitelist $whitelist): array
{
    $streamId = (string)($event['streamId'] ?? '0-0');
    $timestampMs = (int)($event['timestamp'] ?? (int)round(microtime(true) * 1000));

    $model = $whitelist->getModel($imei);
    $caps = $model ? DeviceCapabilities::forModel($model) : null;

    return [
        'schemaVersion' => '1.0',
        'eventType' => 'integration.error',
        'eventId' => eventIdFromStreamId($streamId),
        'occurredAt' => gmdate('Y-m-d\\TH:i:s\\Z', max(0, (int)floor($timestampMs / 1000))),
        'imei' => $imei,
        'model' => $model,
        'supplier' => $caps?->getSupplier(),
        'data' => [
            'code' => $event['code'] ?? 'unknown_error',
            'message' => $event['message'] ?? 'Unknown error',
            'command' => $event['command'] ?? null,
            'protocol' => $event['protocol'] ?? null,
        ],
    ];
}

function buildCommandStatePayload(array $event, string $imei, Whitelist $whitelist): array
{
    $streamId = (string)($event['streamId'] ?? '0-0');
    $timestampMs = (int)($event['timestamp'] ?? (int)round(microtime(true) * 1000));

    $model = $whitelist->getModel($imei);
    $caps = $model ? DeviceCapabilities::forModel($model) : null;

    return [
        'schemaVersion' => '1.0',
        'eventType' => 'command.state.changed',
        'eventId' => eventIdFromStreamId($streamId),
        'occurredAt' => gmdate('Y-m-d\\TH:i:s\\Z', max(0, (int)floor($timestampMs / 1000))),
        'imei' => $imei,
        'model' => $model,
        'supplier' => $caps?->getSupplier(),
        'data' => [
            'state' => $event['state'] ?? null,
            'requestId' => $event['requestId'] ?? null,
            'nativeType' => $event['type'] ?? null,
            'feature' => $event['feature'] ?? null,
            'ident' => $event['ident'] ?? null,
            'reason' => $event['reason'] ?? null,
            'protocol' => $event['protocol'] ?? null,
        ],
    ];
}

function eventIdFromStreamId(string $streamId): string
{
    $normalized = preg_replace('/[^a-zA-Z0-9]/', '_', $streamId) ?: '0_0';
    return 'evt_' . $normalized;
}

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
