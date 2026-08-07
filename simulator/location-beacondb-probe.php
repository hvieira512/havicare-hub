#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Hub\Bootstrap;
use Hub\Config;
use Hub\Location\BeaconDbClient;
use Hub\Location\BeaconDbRequestBuilder;
use Hub\Mqtt\BrokerSettings;
use Hub\Mqtt\ConnectionFactory;
use PhpMqtt\Client\MqttClient;

Bootstrap::loadEnv(__DIR__ . '/..');

$args = parseProbeArgs($argv);
if (isset($args['help']) || isset($args['h'])) {
    probeUsage();
    exit(0);
}

$file = trim((string)($args['file'] ?? ''));
$topic = trim((string)($args['topic'] ?? ''));
if (($file === '') === ($topic === '')) {
    fwrite(STDERR, "Use exactly one of --file PATH or --topic FILTER.\n\n");
    probeUsage();
    exit(1);
}

$endpoint = trim((string)($args['endpoint'] ?? getenv('BEACONDB_ENDPOINT') ?: 'https://api.beacondb.net/v1/geolocate'));
$userAgent = trim((string)($args['user-agent'] ?? getenv('BEACONDB_USER_AGENT') ?: ''));
$timeout = max(0.5, (float)($args['timeout'] ?? 5.0));
$maxAccuracy = max(1.0, (float)($args['max-accuracy'] ?? 5000.0));
$builder = new BeaconDbRequestBuilder();
$client = new BeaconDbClient($endpoint, $timeout);

$process = static function (string $json, string $label = '') use ($builder, $client, $userAgent, $maxAccuracy): bool {
    $payload = json_decode($json, true);
    if (!is_array($payload)) {
        fwrite(STDERR, "Invalid JSON{$label}.\n");
        return false;
    }
    if (($payload['type'] ?? null) !== 'location') {
        fwrite(STDERR, "Ignoring non-location payload{$label}.\n");
        return false;
    }

    $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
    echo "\n=== Location" . ($label !== '' ? " {$label}" : '') . " ===\n";
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

    $lat = numericProbeValue($data['lat'] ?? null);
    $lon = numericProbeValue($data['lon'] ?? null);
    if (($data['gpsValid'] ?? null) === true && validProbeCoordinates($lat, $lon)) {
        echo "GPS: lat={$lat}, lon={$lon} (BeaconDB not called)\n";
        return true;
    }

    $request = $builder->build($payload);
    if ($request === null) {
        echo "UNRESOLVED: insufficient supported cell/Wi-Fi evidence for BeaconDB.\n";
        return false;
    }
    if ($userAgent === '') {
        fwrite(STDERR, "BEACONDB_USER_AGENT or --user-agent is required for a live request.\n");
        return false;
    }

    echo "BeaconDB request:\n";
    echo json_encode($request, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

    try {
        $result = $client->resolve($request, $userAgent);
    } catch (Throwable $e) {
        fwrite(STDERR, 'BeaconDB error: ' . $e->getMessage() . "\n");
        return false;
    }

    echo "BeaconDB HTTP {$result['httpStatus']}:\n";
    echo json_encode($result['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    $resolvedLat = numericProbeValue($result['body']['location']['lat'] ?? null);
    $resolvedLon = numericProbeValue($result['body']['location']['lng'] ?? null);
    $accuracy = numericProbeValue($result['body']['accuracy'] ?? null);
    if ($result['httpStatus'] < 200 || $result['httpStatus'] >= 300 || !validProbeCoordinates($resolvedLat, $resolvedLon)) {
        echo "UNRESOLVED: BeaconDB did not return valid coordinates.\n";
        return false;
    }

    $accuracyLabel = $accuracy === null ? 'unknown' : (string)$accuracy;
    echo "RESOLVED: lat={$resolvedLat}, lon={$resolvedLon}, accuracyMeters={$accuracyLabel}\n";
    if ($accuracy !== null && $accuracy > $maxAccuracy) {
        echo "REJECTED: accuracy exceeds {$maxAccuracy} meters.\n";
        return false;
    }

    return true;
};

if ($file !== '') {
    $json = @file_get_contents($file);
    if (!is_string($json)) {
        fwrite(STDERR, "Unable to read {$file}.\n");
        exit(1);
    }
    exit($process($json, $file) ? 0 : 2);
}

$config = Config::load()->all();
$mqtt = $config['mqtt'] ?? [];
$host = (string)($args['host'] ?? ($mqtt['host'] ?? '127.0.0.1'));
$port = (int)($args['port'] ?? ($mqtt['port'] ?? 1883));
$username = (string)($args['username'] ?? ($mqtt['username'] ?? ''));
$password = (string)($args['password'] ?? ($mqtt['password'] ?? ''));
$once = isset($args['once']);
$expectedCount = max(1, (int)($args['count'] ?? ($once ? 1 : PHP_INT_MAX)));
$listenTimeout = max(1.0, (float)($args['listen-timeout'] ?? 60.0));
$received = 0;
$successful = 0;

$connections = new ConnectionFactory(new BrokerSettings(
    $host,
    $port,
    $username,
    $password,
    'location-probe',
    keepalive: 30,
    connectTimeout: 5,
    socketTimeout: 5,
));
$mqttClient = $connections->create(bin2hex(random_bytes(3)));

try {
    $connections->connect($mqttClient, true);
    $mqttClient->subscribe($topic, static function (string $messageTopic, string $message) use (&$received, &$successful, $process): void {
        $decoded = json_decode($message, true);
        if (!is_array($decoded) || ($decoded['type'] ?? null) !== 'location') {
            return;
        }
        $received++;
        if ($process($message, "from {$messageTopic}")) {
            $successful++;
        }
    }, MqttClient::QOS_AT_MOST_ONCE);

    echo "Listening on mqtt://{$host}:{$port}/{$topic}\n";
    $deadline = microtime(true) + $listenTimeout;
    while (microtime(true) < $deadline && $received < $expectedCount) {
        $mqttClient->loopOnce(microtime(true), false, 100000);
    }
    $mqttClient->disconnect();
} catch (Throwable $e) {
    fwrite(STDERR, 'MQTT probe failed: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($received === 0) {
    fwrite(STDERR, "No normalized location telemetry received before timeout.\n");
    exit(2);
}
if ($successful !== $received) {
    fwrite(STDERR, "Resolved {$successful} of {$received} received location messages.\n");
    exit(2);
}
exit(0);

function parseProbeArgs(array $argv): array
{
    $args = [];
    for ($index = 1; $index < count($argv); $index++) {
        $token = $argv[$index];
        if (!str_starts_with($token, '--')) {
            continue;
        }
        $token = substr($token, 2);
        if (str_contains($token, '=')) {
            [$key, $value] = explode('=', $token, 2);
            $args[$key] = $value;
            continue;
        }
        $next = $argv[$index + 1] ?? null;
        if (is_string($next) && !str_starts_with($next, '--')) {
            $args[$token] = $next;
            $index++;
        } else {
            $args[$token] = true;
        }
    }
    return $args;
}

function numericProbeValue(mixed $value): ?float
{
    return $value === null || $value === '' || !is_numeric((string)$value) ? null : (float)$value;
}

function validProbeCoordinates(?float $lat, ?float $lon): bool
{
    return $lat !== null && $lon !== null
        && $lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180
        && !($lat === 0.0 && $lon === 0.0);
}

function probeUsage(): void
{
    echo <<<'TXT'
Local normalized-location -> BeaconDB probe

Usage:
  php simulator/location-beacondb-probe.php --file telemetry.json --user-agent 'HaviCare location test (ops@example.com)'
  php simulator/location-beacondb-probe.php --topic 'havicare-hub/+/+/watch/+/telemetry' --once --user-agent 'HaviCare location test (ops@example.com)'

Options:
  --file PATH              Read one normalized schema-v2 telemetry payload.
  --topic FILTER           Subscribe to an MQTT telemetry topic/filter.
  --host HOST              MQTT host (defaults to repository MQTT configuration).
  --port PORT              MQTT port.
  --username USER          MQTT username.
  --password PASS          MQTT password.
  --once                   Exit after the first location message.
  --count NUMBER           Exit successfully after this many resolved locations.
  --listen-timeout SEC     MQTT wait timeout (default: 60).
  --endpoint URL           BeaconDB endpoint.
  --user-agent VALUE       Required descriptive BeaconDB User-Agent.
  --timeout SEC            BeaconDB HTTP timeout (default: 5).
  --max-accuracy METERS    Reject less accurate results (default: 5000).

Environment:
  BEACONDB_ENDPOINT, BEACONDB_USER_AGENT
TXT;
    echo PHP_EOL;
}
