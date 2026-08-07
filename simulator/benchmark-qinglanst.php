#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Hub\Bootstrap;
use Hub\Config;
use Hub\Dashboard\DashboardStore;
use Hub\HubMqttBridge;
use Hub\Ingress\Mqtt\Qinglanst\DashboardWritePolicy;
use Hub\Ingress\Mqtt\Qinglanst\MessageNormalizer;
use Hub\Ingress\Mqtt\Qinglanst\PayloadDecoder;
use Hub\Ingress\Mqtt\Qinglanst\Topic;
use Hub\Registry\Whitelist;
use Hub\Mqtt\BrokerSettings;
use Hub\Mqtt\ConnectionFactory;
use Hub\Runtime\HubServices;
use Predis\Client as RedisClient;

Bootstrap::loadEnv(__DIR__ . '/..');

$options = getopt('', [
    'input:',
    'loops::',
    'limit::',
    'mqtt-host::',
    'mqtt-port::',
    'mqtt-user::',
    'mqtt-pass::',
    'mqtt-prefix::',
    'redis-host::',
    'redis-port::',
    'redis-pass::',
    'history-limit::',
    'seen-min-ms::',
    'position-sample-ms::',
]);

$input = $options['input'] ?? '';
if ($input === '' || !is_file($input)) {
    fwrite(STDERR, "Usage: php simulator/benchmark-qinglanst.php --input CAPTURE.log [--loops 50] [--limit 1000]\n");
    exit(1);
}

$config = Config::load()->all();
$mqttConfig = $config['mqtt'] ?? [];
$redisConfig = $config['redis'] ?? [];
$historyLimit = max(1, (int)($options['history-limit'] ?? ($config['dashboard']['history_limit'] ?? 100)));
$loops = max(1, (int)($options['loops'] ?? 1));
$limit = max(1, (int)($options['limit'] ?? 500));

$mqttHost = (string)($options['mqtt-host'] ?? ($mqttConfig['host'] ?? '127.0.0.1'));
$mqttPort = (int)($options['mqtt-port'] ?? ($mqttConfig['port'] ?? 1883));
$mqttUser = (string)($options['mqtt-user'] ?? ($mqttConfig['username'] ?? ''));
$mqttPass = (string)($options['mqtt-pass'] ?? ($mqttConfig['password'] ?? ''));
$mqttPrefix = trim((string)($options['mqtt-prefix'] ?? ($mqttConfig['topic_prefix'] ?? '')), '/');

$redisHost = (string)($options['redis-host'] ?? ($redisConfig['host'] ?? '127.0.0.1'));
$redisPort = (int)($options['redis-port'] ?? ($redisConfig['port'] ?? 6379));
$redisPass = (string)($options['redis-pass'] ?? ($redisConfig['password'] ?? ''));

$redis = new RedisClient(HubServices::redisParameters([
    'host' => $redisHost,
    'port' => $redisPort,
    'password' => $redisPass,
]));
$dashboardStore = new DashboardStore($redis, $historyLimit, 'hub:dashboard:benchmark:qinglanst');
$writePolicy = new DashboardWritePolicy(
    max(0, (int)($options['seen-min-ms'] ?? 5000)),
    max(0, (int)($options['position-sample-ms'] ?? 1000)),
);

$whitelist = new Whitelist();
$decoder = new PayloadDecoder();
$normalizer = new MessageNormalizer();

$mqttClient = (new ConnectionFactory(new BrokerSettings(
    $mqttHost,
    $mqttPort,
    $mqttUser,
    $mqttPass,
    'qinglanst-bench',
    keepalive: 60,
    connectTimeout: 5,
    socketTimeout: 5,
)))->build('run');
$mqttBridge = new HubMqttBridge($mqttClient, $mqttPrefix);

$samples = loadSamples($input, $limit);
if ($samples === []) {
    fwrite(STDERR, "No valid radar samples found in {$input}\n");
    exit(1);
}

$accepted = 0;
$rejected = 0;
$telemetryPublishes = 0;
$eventPublishes = 0;
$timings = [
    'json' => 0,
    'resolve' => 0,
    'decode' => 0,
    'normalize' => 0,
    'redis_seen' => 0,
    'mqtt_telemetry' => 0,
    'redis_telemetry' => 0,
    'mqtt_event' => 0,
    'redis_event' => 0,
    'total' => 0,
];
$peak = array_fill_keys(array_keys($timings), 0);
$licenseCounts = [];
$uidCounts = [];

$startAll = hrtime(true);
for ($loop = 0; $loop < $loops; $loop++) {
    foreach ($samples as $sample) {
        $totalStart = hrtime(true);
        $parsedTopic = Topic::parse($sample['topic']);
        if ($parsedTopic === null) {
            $rejected++;
            continue;
        }

        $jsonStart = hrtime(true);
        $decodedEnvelope = json_decode($sample['payload'], true);
        $timings['json'] += hrtime(true) - $jsonStart;
        if (!is_array($decodedEnvelope)) {
            $rejected++;
            continue;
        }

        $payload = $decodedEnvelope['payload'] ?? $decodedEnvelope;
        if (!is_array($payload)) {
            $rejected++;
            continue;
        }

        $messageType = detectMessageType($payload);
        if ($messageType === null) {
            $rejected++;
            continue;
        }

        $resolveStart = hrtime(true);
        $deviceUid = $parsedTopic->deviceUid;
        $device = $whitelist->resolve($deviceUid, 'qinglanst', $deviceUid);
        if ($device === null) {
            $device = syntheticRadarDevice($parsedTopic->deviceUid, $parsedTopic->licenseId);
        }
        $timings['resolve'] += hrtime(true) - $resolveStart;

        $decodeStart = hrtime(true);
        $decoded = $decoder->decode($messageType, (string)($payload[$messageType] ?? ''), (string)($payload['deviceCode'] ?? $deviceUid));
        $timings['decode'] += hrtime(true) - $decodeStart;
        if ($decoded === null) {
            $rejected++;
            continue;
        }

        $normalizeStart = hrtime(true);
        $normalized = $normalizer->normalize($decoded, $parsedTopic, $device);
        $timings['normalize'] += hrtime(true) - $normalizeStart;

        $dashboardKey = (string)$device['imei'];
        $topicDeviceKey = $parsedTopic->deviceUid;
        $deviceType = (string)$device['deviceType'];
        $licenseId = (string)$device['licenseId'];
        $company = (string)($device['company'] ?? 'null');
        $nowMs = (int) floor(microtime(true) * 1000);

        if ($writePolicy->shouldUpdateSeen($dashboardKey, $nowMs)) {
            $seenStart = hrtime(true);
            $dashboardStore->deviceSeen($dashboardKey, [
                'supplier' => (string)$device['supplier'],
                'model' => (string)$device['model'],
                'deviceType' => $deviceType,
                'licenseId' => $licenseId,
                'company' => $company,
                'protocol' => 'qinglanst-radar',
                'transport' => 'mqtt',
                'online' => '1',
            ]);
            $timings['redis_seen'] += hrtime(true) - $seenStart;
        }

        if (isset($normalized['telemetry']) && is_array($normalized['telemetry'])) {
            $mqttStart = hrtime(true);
            $mqttBridge->publishTelemetry($topicDeviceKey, $normalized['telemetry'], $deviceType, $licenseId, $company);
            $duration = hrtime(true) - $mqttStart;
            $timings['mqtt_telemetry'] += $duration;
            $peak['mqtt_telemetry'] = max($peak['mqtt_telemetry'], $duration);

            if ($writePolicy->shouldStoreTelemetry($dashboardKey, (string)($normalized['telemetry']['type'] ?? ''), $nowMs)) {
                $redisStart = hrtime(true);
                $dashboardStore->append($dashboardKey, 'telemetry', array_merge($normalized['telemetry'], [
                    'deviceType' => $deviceType,
                    'licenseId' => $licenseId,
                ]));
                $duration = hrtime(true) - $redisStart;
                $timings['redis_telemetry'] += $duration;
                $peak['redis_telemetry'] = max($peak['redis_telemetry'], $duration);
            }
            $telemetryPublishes++;
        }

        if (isset($normalized['event']) && is_array($normalized['event'])) {
            $mqttStart = hrtime(true);
            $mqttBridge->publishEvent($topicDeviceKey, $normalized['event'], $deviceType, $licenseId, $company);
            $duration = hrtime(true) - $mqttStart;
            $timings['mqtt_event'] += $duration;
            $peak['mqtt_event'] = max($peak['mqtt_event'], $duration);

            $redisStart = hrtime(true);
            $dashboardStore->append($dashboardKey, 'events', array_merge($normalized['event'], [
                'deviceType' => $deviceType,
                'licenseId' => $licenseId,
            ]));
            $duration = hrtime(true) - $redisStart;
            $timings['redis_event'] += $duration;
            $peak['redis_event'] = max($peak['redis_event'], $duration);
            $eventPublishes++;
        }

        $accepted++;
        $licenseCounts[$parsedTopic->licenseId] = ($licenseCounts[$parsedTopic->licenseId] ?? 0) + 1;
        $uidCounts[$parsedTopic->deviceUid] = ($uidCounts[$parsedTopic->deviceUid] ?? 0) + 1;

        $totalDuration = hrtime(true) - $totalStart;
        $timings['total'] += $totalDuration;
        $peak['total'] = max($peak['total'], $totalDuration);
    }
}
$elapsedNs = hrtime(true) - $startAll;
$mqttClient->disconnect();

$totalMessages = $accepted + $rejected;
$avg = [];
foreach ($timings as $key => $value) {
    $avg[$key] = $accepted > 0 ? round(($value / $accepted) / 1_000_000, 3) : 0.0;
}
$max = [];
foreach ($peak as $key => $value) {
    $max[$key] = round($value / 1_000_000, 3);
}

arsort($licenseCounts);
arsort($uidCounts);

echo json_encode([
    'input' => $input,
    'samples' => count($samples),
    'loops' => $loops,
    'messages_total' => $totalMessages,
    'accepted' => $accepted,
    'rejected' => $rejected,
    'telemetry_publishes' => $telemetryPublishes,
    'event_publishes' => $eventPublishes,
    'elapsed_s' => round($elapsedNs / 1_000_000_000, 3),
    'accepted_msg_per_s' => $elapsedNs > 0 ? round($accepted / ($elapsedNs / 1_000_000_000), 2) : 0.0,
    'avg_ms' => $avg,
    'max_ms' => $max,
    'licenses' => $licenseCounts,
    'top_uids' => array_slice($uidCounts, 0, 20, true),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;

/**
 * @return list<array{topic: string, payload: string}>
 */
function loadSamples(string $input, int $limit): array
{
    $samples = [];
    $lines = file($input, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        if (count($samples) >= $limit) {
            break;
        }

        $space = strpos($line, ' ');
        if ($space === false) {
            continue;
        }

        $topic = trim(substr($line, 0, $space));
        $payload = trim(substr($line, $space + 1));
        if (!str_starts_with($topic, 'radar/')) {
            continue;
        }

        $samples[] = ['topic' => $topic, 'payload' => $payload];
    }

    return $samples;
}

function detectMessageType(array $payload): ?string
{
    foreach (['position', 'heartbreath', 'posstatics', 'hbstatics'] as $type) {
        if (!empty($payload[$type])) {
            return $type;
        }
    }

    return null;
}

/**
 * @return array{imei: string, supplier: string, model: string, deviceType: string, licenseId: string, company: string, simNumber: string, deviceId: string}
 */
function syntheticRadarDevice(string $deviceUid, string $licenseId): array
{
    return [
        'imei' => $deviceUid,
        'supplier' => 'Qinglanst',
        'model' => 'RD-V1',
        'deviceType' => 'radar',
        'licenseId' => trim($licenseId) !== '' ? $licenseId : '0',
        'company' => 'benchmark',
        'simNumber' => '',
        'deviceId' => $deviceUid,
    ];
}
