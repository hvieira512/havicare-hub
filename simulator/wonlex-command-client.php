#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Bootstrap;
use App\Config;
use App\Protocol\Adapter\WonlexAdapter;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

Bootstrap::loadEnv(__DIR__ . '/..');

$args = parseArgs($argv);
$config = Config::load()->all();
$mqttConfig = $config['mqtt'] ?? [];

if (isset($args['help']) || isset($args['h'])) {
    usage();
    exit(0);
}

$imei = (string)($args['imei'] ?? '');
if ($imei === '') {
    usage();
    exit(1);
}

$host = (string)($args['host'] ?? ($mqttConfig['host'] ?? '127.0.0.1'));
$port = (int)($args['port'] ?? ($mqttConfig['port'] ?? 1883));
$username = (string)($args['username'] ?? ($mqttConfig['username'] ?? ''));
$password = (string)($args['password'] ?? ($mqttConfig['password'] ?? ''));
$topicPrefix = trim((string)($args['topic-prefix'] ?? ($mqttConfig['topic_prefix'] ?? 'hitecosystem-hub')), '/');
$timeoutSeconds = max(0.5, (float)($args['timeout'] ?? 8.0));
$settleSeconds = max(0.1, (float)($args['settle'] ?? 1.0));
$commandFilter = trim((string)($args['command'] ?? ''));
$riskFilter = parseCsv((string)($args['include-risk'] ?? 'normal'));
$payloadOverride = isset($args['payload']) ? decodeJsonObject((string)$args['payload']) : null;
$listCommands = isset($args['list-commands']);
$showRaw = isset($args['show-raw']);

$adapter = new WonlexAdapter();
$manifest = wonlexCommands();

if ($listCommands) {
    printCommandCatalog($manifest, wonlexUplinks());
    exit(0);
}

$selected = $commandFilter !== ''
    ? array_values(array_filter($manifest, static fn(array $entry): bool => $entry['command'] === $commandFilter))
    : array_values(array_filter(
        $manifest,
        static fn(array $entry): bool => in_array((string)($entry['risk'] ?? 'normal'), $riskFilter, true)
            && (string)($entry['kind'] ?? 'request') === 'request'
    ));

if ($commandFilter !== '' && $selected === []) {
    fwrite(STDERR, "Unknown Wonlex command: {$commandFilter}\n");
    exit(1);
}

if ($selected === []) {
    fwrite(STDERR, "No commands selected. Use --include-risk normal,high or --command dnHeartRate.\n");
    exit(1);
}

$clientId = substr('health-wonlex-command-' . getmypid() . '-' . bin2hex(random_bytes(4)), 0, 23);
$client = new MqttClient($host, $port, $clientId);

$settings = (new ConnectionSettings())
    ->setUsername($username !== '' ? $username : null)
    ->setPassword($password !== '' ? $password : null)
    ->setKeepAliveInterval(30)
    ->setConnectTimeout(5)
    ->setSocketTimeout(5);

try {
    $client->connect($settings, true);
} catch (Throwable $e) {
    fwrite(STDERR, "Failed to connect to MQTT broker {$host}:{$port} as " . ($username !== '' ? $username : '<anonymous>') . "\n");
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    fwrite(STDERR, "Check MQTT_HOST, MQTT_PORT, MQTT_USERNAME and MQTT_PASSWORD in .env, or override them with CLI flags.\n");
    exit(1);
}

$eventsTopic = topic($topicPrefix, "devices/$imei/events");
$telemetryTopic = topic($topicPrefix, "devices/$imei/telemetry");
$rawTopic = topic($topicPrefix, "devices/$imei/raw");
$downlinkTopic = topic($topicPrefix, "devices/$imei/downlink");

$messages = [];
$client->subscribe($eventsTopic, static function (string $topic, string $payload) use (&$messages): void {
    $messages[] = recordMessage($topic, $payload);
}, MqttClient::QOS_AT_MOST_ONCE);

$client->subscribe($telemetryTopic, static function (string $topic, string $payload) use (&$messages): void {
    $messages[] = recordMessage($topic, $payload);
}, MqttClient::QOS_AT_MOST_ONCE);

$client->subscribe($rawTopic, static function (string $topic, string $payload) use (&$messages): void {
    $messages[] = recordMessage($topic, $payload);
}, MqttClient::QOS_AT_MOST_ONCE);

echo "Connected to {$host}:{$port}" . PHP_EOL;
echo "Watching: {$eventsTopic}" . PHP_EOL;
echo "Watching: {$telemetryTopic}" . PHP_EOL;
echo $showRaw ? "Watching: {$rawTopic}" . PHP_EOL : "Watching raw internally for device replies. Use --show-raw to print it." . PHP_EOL;
echo PHP_EOL;

drainLoop($client, 0.25);
clearMessages($messages);

foreach ($selected as $entry) {
    $command = (string)$entry['command'];
    $risk = (string)($entry['risk'] ?? 'normal');
    $title = (string)($entry['title'] ?? '');
    $replyTypes = $entry['expectedReplyTypes'] ?? [];

    if ($risk !== '' && !in_array($risk, $riskFilter, true)) {
        echo "[SKIP] {$command} {$title} [{$risk}]" . PHP_EOL;
        continue;
    }

    $requestId = randomHex(8);
    $ident = randomIdent();
    $payload = buildDownlinkPayload($imei, $command, $requestId, $ident, $payloadOverride);
    $before = count($messages);

    echo "[SEND] {$command} {$title}" . PHP_EOL;
    if (!empty($replyTypes)) {
        echo "       expected replies: " . implode(', ', $replyTypes) . PHP_EOL;
    }
    echo "       requestId: {$requestId}" . PHP_EOL;
    echo "       ident: {$ident}" . PHP_EOL;
    echo "       payload: " . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

    $client->publish(
        $downlinkTopic,
        json_encode([
            'encoding' => 'base64',
            'payload' => base64_encode($adapter->encodeOutgoing($payload)),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        MqttClient::QOS_AT_MOST_ONCE,
        false
    );

    $captured = waitForMessages($client, $messages, $before, $timeoutSeconds, $settleSeconds, $command, $replyTypes);
    if ($captured === []) {
        echo "  [timeout] no MQTT response within {$timeoutSeconds}s" . PHP_EOL . PHP_EOL;
        continue;
    }

    foreach (visibleMessages($captured, $showRaw) as $message) {
        echo '  ' . highlightTopic($message['topic']) . PHP_EOL;
        echo indent(prettyPayload($message['payload'])) . PHP_EOL;
    }

    $replyMessages = array_values(array_filter($captured, static fn(array $message): bool => isDeviceReply($message)));
    $matchingNativeReplies = array_values(array_filter($captured, static fn(array $message): bool => isMatchingNativeReply($message, $command, $replyTypes)));
    $decodedReplies = array_values(array_filter($captured, static fn(array $message): bool => isExpectedDecodedReply($message, $replyTypes)));
    $downlinkState = downlinkState($captured);

    if ($downlinkState !== null) {
        echo "  " . describeDownlinkState($downlinkState) . PHP_EOL;
    }

    if (($downlinkState['type'] ?? null) === 'device.downlink.queued') {
        echo "  [queued] device is offline; command will be sent after the next login" . PHP_EOL;
    } elseif (($downlinkState['type'] ?? null) === 'device.downlink.dropped') {
        echo "  [dropped] command was not delivered" . PHP_EOL;
    } elseif ($replyMessages === [] && $decodedReplies === []) {
        echo "  [sent] no device reply observed yet" . PHP_EOL;
    } elseif ($matchingNativeReplies === [] && $decodedReplies === []) {
        echo "  [sent] only unrelated device uplink(s) were observed" . PHP_EOL;
    } elseif ($decodedReplies === [] && !$showRaw) {
        echo "  [ok] device replied with " . count($matchingNativeReplies) . " matching native message(s), but no expected decoded telemetry was produced. Use --show-raw to inspect." . PHP_EOL;
    } else {
        echo "  [ok] device replied with " . count($matchingNativeReplies) . " matching native message(s) and " . count($decodedReplies) . " expected decoded telemetry message(s)" . PHP_EOL;
    }

    echo PHP_EOL;
}

$client->disconnect();

function usage(): void
{
    echo <<<TXT
Usage:
  php simulator/wonlex-command-client.php --imei IMEI --host HOST --port PORT --username USER --password PASS [options]

Options:
  --command dnHeartRate   Run a single command instead of the full set.
  --payload JSON          Override the payload object sent for every command.
  --include-risk normal,high
                          Select risk level for bulk request runs. Default: normal.
  --timeout 8             Maximum seconds to wait for replies per command.
  --settle 1.0            Stop early after this many quiet seconds.
  --topic-prefix PREFIX    MQTT topic prefix. Default: hitecosystem-hub
  --show-raw              Print raw MQTT packets. By default raw is used only to detect device replies.
  --list-commands         Print server downlinks and device uplinks, then exit.

Notes:
  - Replies are read from devices/{imei}/events, devices/{imei}/telemetry and devices/{imei}/raw.
  - Commands listed under "server -> device" can be used with --command.
  - Bulk runs only send request commands; use --command to send config/control/data commands explicitly.
  - Commands listed under "device -> server" are native uplinks the device may send.
  - The destructive reset/restart/powerOff commands are behind --include-risk high.
  - This command client publishes base64-encoded Wonlex JSON frames to devices/{imei}/downlink.

TXT;
}

function parseArgs(array $argv): array
{
    $args = [];
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if (!str_starts_with($arg, '--')) {
            continue;
        }

        $key = substr($arg, 2);
        if (($argv[$i + 1] ?? '') !== '' && !str_starts_with($argv[$i + 1], '--')) {
            $args[$key] = $argv[++$i];
        } else {
            $args[$key] = true;
        }
    }

    return $args;
}

function parseCsv(string $value): array
{
    $parts = array_map('trim', explode(',', $value));
    $parts = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
    return $parts !== [] ? $parts : ['normal'];
}

function decodeJsonObject(string $value): array
{
    $decoded = json_decode($value, true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "Failed to decode --payload JSON\n");
        exit(1);
    }

    return $decoded;
}

function wonlexCommands(): array
{
    return [
        ['command' => 'dnHeartRate', 'title' => 'Request heart rate', 'kind' => 'request', 'risk' => 'normal', 'expectedReplyTypes' => ['upHeartRate', 'upBatch']],
        ['command' => 'dnBP', 'title' => 'Request blood pressure', 'kind' => 'request', 'risk' => 'normal', 'expectedReplyTypes' => ['upBP', 'upBatch']],
        ['command' => 'dnBO', 'title' => 'Request blood oxygen', 'kind' => 'request', 'risk' => 'normal', 'expectedReplyTypes' => ['upBO', 'upBatch']],
        ['command' => 'dnTemperature', 'title' => 'Request temperature', 'kind' => 'request', 'risk' => 'normal', 'expectedReplyTypes' => ['upBodyTemperature']],
        ['command' => 'dnBreathe', 'title' => 'Request respiration', 'kind' => 'request', 'risk' => 'normal', 'expectedReplyTypes' => ['upBreathe']],
        ['command' => 'dnECG', 'title' => 'Request ECG', 'kind' => 'request', 'risk' => 'normal', 'expectedReplyTypes' => ['upECG']],
        ['command' => 'dnECGAnalysis', 'title' => 'Issue ECG analysis results', 'kind' => 'data', 'risk' => 'normal', 'expectedReplyTypes' => []],
        ['command' => 'dnHRV', 'title' => 'Request HRV', 'kind' => 'request', 'risk' => 'normal', 'expectedReplyTypes' => ['upHRV']],
        ['command' => 'dnPPG', 'title' => 'Request PPG', 'kind' => 'request', 'risk' => 'normal', 'expectedReplyTypes' => ['upPPG']],
        ['command' => 'dnRR', 'title' => 'Request RR interval', 'kind' => 'request', 'risk' => 'normal', 'expectedReplyTypes' => ['upRR']],
        ['command' => 'dnLocation', 'title' => 'Request location', 'kind' => 'request', 'risk' => 'normal', 'expectedReplyTypes' => ['upLocation']],
        ['command' => 'locationInterval', 'title' => 'Set location interval', 'kind' => 'config', 'risk' => 'normal', 'expectedReplyTypes' => ['upDeviceConfig']],
        ['command' => 'deviceConfig', 'title' => 'Set device config', 'kind' => 'config', 'risk' => 'normal', 'expectedReplyTypes' => ['upDeviceConfig']],
        ['command' => 'alarmClock', 'title' => 'Set alarm clock', 'kind' => 'config', 'risk' => 'normal', 'expectedReplyTypes' => ['upDeviceConfig']],
        ['command' => 'SOSNumber', 'title' => 'Set SOS numbers', 'kind' => 'config', 'risk' => 'normal', 'expectedReplyTypes' => ['upDeviceConfig']],
        ['command' => 'dnUpSleep', 'title' => 'Send sleep data', 'kind' => 'data', 'risk' => 'normal', 'expectedReplyTypes' => []],
        ['command' => 'dnWeather', 'title' => 'Issue weather data', 'kind' => 'data', 'risk' => 'normal', 'expectedReplyTypes' => []],
        ['command' => 'dnMedicationPlan', 'title' => 'Set medication plan', 'kind' => 'config', 'risk' => 'normal', 'expectedReplyTypes' => ['upDeviceConfig']],
        ['command' => 'dnDevBindStatus', 'title' => 'Issue device binding status', 'kind' => 'config', 'risk' => 'normal', 'expectedReplyTypes' => []],
        ['command' => 'findPhoneBillOrFlow', 'title' => 'Request phone bill or flow', 'kind' => 'request', 'risk' => 'normal', 'expectedReplyTypes' => []],
        ['command' => 'find', 'title' => 'Find device', 'kind' => 'control', 'risk' => 'normal', 'expectedReplyTypes' => []],
        ['command' => 'OTA', 'title' => 'OTA update', 'kind' => 'control', 'risk' => 'high', 'expectedReplyTypes' => ['upGetOTA']],
        ['command' => 'reset', 'title' => 'Factory reset', 'kind' => 'control', 'risk' => 'high', 'expectedReplyTypes' => ['upReset']],
        ['command' => 'restart', 'title' => 'Restart device', 'kind' => 'control', 'risk' => 'high', 'expectedReplyTypes' => ['upShutdown']],
        ['command' => 'powerOff', 'title' => 'Power off device', 'kind' => 'control', 'risk' => 'high', 'expectedReplyTypes' => ['upShutdown']],
    ];
}

function wonlexUplinks(): array
{
    return [
        ['command' => 'login', 'origin' => 'device-init', 'features' => ['status'], 'notes' => 'Sent when the device opens a session.'],
        ['command' => 'heartbeat', 'origin' => 'server-probe', 'features' => ['heartbeat', 'battery'], 'notes' => 'Reply to server heartbeat; includes battery fields.'],
        ['command' => 'upHeartRate', 'origin' => 'scheduled/manual/request', 'features' => ['heart_rate'], 'notes' => 'May also be included in upBatch.'],
        ['command' => 'upBP', 'origin' => 'scheduled/manual/request', 'features' => ['blood_pressure'], 'notes' => 'May also be included in upBatch.'],
        ['command' => 'upBO', 'origin' => 'scheduled/manual/request', 'features' => ['blood_oxygen'], 'notes' => 'May also be included in upBatch.'],
        ['command' => 'upBodyTemperature', 'origin' => 'scheduled/manual/request', 'features' => ['temperature'], 'notes' => 'Body/surface/environment temperature payload.'],
        ['command' => 'upBreathe', 'origin' => 'scheduled/manual/request', 'features' => ['respiration'], 'notes' => 'Respiration measurement upload.'],
        ['command' => 'upECG', 'origin' => 'scheduled/manual/request', 'features' => ['ecg'], 'notes' => 'Waveform upload; may be multi-packet.'],
        ['command' => 'upECGAnalysis', 'origin' => 'request', 'features' => ['ecg_analysis'], 'notes' => 'ECG analysis result.'],
        ['command' => 'upHRV', 'origin' => 'scheduled/manual/request', 'features' => ['hrv'], 'notes' => 'Heart-rate variability upload.'],
        ['command' => 'upPPG', 'origin' => 'scheduled/manual/request', 'features' => ['ppg'], 'notes' => 'PPG waveform upload.'],
        ['command' => 'upRR', 'origin' => 'scheduled/manual/request', 'features' => ['rr_interval'], 'notes' => 'RRI upload.'],
        ['command' => 'upBatch', 'origin' => 'scheduled/manual/request', 'features' => ['heart_rate', 'blood_pressure', 'blood_oxygen'], 'notes' => 'Batch health upload; dataType identifies the measurement.'],
        ['command' => 'upLocation', 'origin' => 'scheduled/request', 'features' => ['location'], 'notes' => 'Periodic by locationInterval or reply to dnLocation.'],
        ['command' => 'upBattery', 'origin' => 'scheduled', 'features' => ['battery'], 'notes' => 'Battery upload at regular intervals.'],
        ['command' => 'upStep', 'origin' => 'scheduled', 'features' => ['activity'], 'notes' => 'Step upload according to measurement frequency.'],
        ['command' => 'upKcal', 'origin' => 'scheduled', 'features' => ['activity'], 'notes' => 'Calories upload.'],
        ['command' => 'upDistance', 'origin' => 'scheduled', 'features' => ['activity'], 'notes' => 'Distance upload.'],
        ['command' => 'upTodayActivity', 'origin' => 'scheduled', 'features' => ['activity'], 'notes' => 'Latest daily activity snapshot.'],
        ['command' => 'upRun', 'origin' => 'scheduled/manual', 'features' => ['activity'], 'notes' => 'Running exercise upload.'],
        ['command' => 'upWalk', 'origin' => 'scheduled/manual', 'features' => ['activity'], 'notes' => 'Walking exercise upload.'],
        ['command' => 'upSleep', 'origin' => 'scheduled/request', 'features' => ['sleep'], 'notes' => 'Sleep data upload or reply to dnUpSleep.'],
        ['command' => 'upWeather', 'origin' => 'request', 'features' => ['weather'], 'notes' => 'Reply to dnWeather.'],
        ['command' => 'upDeviceConfig', 'origin' => 'config-reply', 'features' => ['device_config'], 'notes' => 'Acknowledges config downlinks such as deviceConfig/locationInterval/alarmClock/SOSNumber.'],
        ['command' => 'upGetDevConfig', 'origin' => 'request/reply', 'features' => ['device_config'], 'notes' => 'Device configuration report.'],
        ['command' => 'upGetOTA', 'origin' => 'ota-reply', 'features' => ['ota'], 'notes' => 'OTA acknowledgement/status.'],
        ['command' => 'upReset', 'origin' => 'device-init/control-reply', 'features' => ['device_state'], 'notes' => 'Device-initiated factory reset or reply to reset.'],
        ['command' => 'upShutdown', 'origin' => 'control-reply', 'features' => ['device_state'], 'notes' => 'Reply to restart or powerOff before disconnecting.'],
    ];
}

function printCommandCatalog(array $downlinks, array $uplinks): void
{
    echo "Wonlex server -> device commands usable with --command" . PHP_EOL;
    echo "COMMAND                 KIND      RISK    EXPECTED DEVICE UPLINKS          TITLE" . PHP_EOL;
    foreach ($downlinks as $entry) {
        printf(
            "%-23s %-9s %-7s %-32s %s" . PHP_EOL,
            (string)$entry['command'],
            (string)($entry['kind'] ?? 'request'),
            (string)($entry['risk'] ?? 'normal'),
            implode(',', $entry['expectedReplyTypes'] ?? []) ?: '-',
            (string)($entry['title'] ?? '')
        );
    }

    echo PHP_EOL;
    echo "Wonlex device -> server native uplinks" . PHP_EOL;
    echo "UPLINK                 ORIGIN                    FEATURES                         NOTES" . PHP_EOL;
    foreach ($uplinks as $entry) {
        printf(
            "%-22s %-25s %-32s %s" . PHP_EOL,
            (string)$entry['command'],
            (string)$entry['origin'],
            implode(',', $entry['features'] ?? []),
            (string)($entry['notes'] ?? '')
        );
    }
}

function buildDownlinkPayload(string $imei, string $command, string $requestId, int $ident, ?array $payloadOverride): array
{
    $timestamp = (int)round(microtime(true) * 1000);
    $data = array_replace([
        'type' => $command,
        'imei' => $imei,
        'timestamp' => $timestamp,
    ], defaultCommandData($command, $timestamp));

    if ($payloadOverride !== null) {
        $data = array_replace($data, $payloadOverride);
    }

    return [
        'type' => $command,
        'ident' => $ident,
        'ref' => 's:down',
        'imei' => $imei,
        'data' => $data,
        'timestamp' => $timestamp,
    ];
}

function defaultCommandData(string $command, int $timestamp): array
{
    return match ($command) {
        'dnECG', 'dnHRV', 'dnPPG', 'dnRR' => [
            'frequency' => '200',
            'oneTime' => 300,
            'collectionLogo' => (string)random_int(10000000, 99999999),
        ],
        'findPhoneBillOrFlow' => [
            'queryType' => 1,
        ],
        'dnECGAnalysis' => [
            'heartRate' => '0',
            'BSc' => '0',
            'BScIndex' => '4',
            'BPcDia' => '0',
            'BPcSys' => '0',
        ],
        'dnUpSleep' => [
            'upDayStr' => gmdate('Y-m-d', (int)floor($timestamp / 1000)),
            'value' => '0/0/0/0',
        ],
        'dnWeather' => [
            'iIsCDMA' => '0',
            'weather' => 'Cloudy',
            'weatherType' => 1,
            'province' => '',
            'city' => '',
            'adcode' => '',
            'temperature' => '',
            'winddirection' => '',
            'windpower' => '',
            'humidity' => '',
            'daytemp' => '',
            'nighttemp' => '',
            'reporttime' => gmdate('Y-m-d H:i:s', (int)floor($timestamp / 1000)),
        ],
        default => [],
    };
}

function randomIdent(): int
{
    return random_int(100000, 999999);
}

function randomHex(int $bytes): string
{
    return bin2hex(random_bytes($bytes));
}

function topic(string $prefix, string $suffix): string
{
    $suffix = trim($suffix, '/');
    return $prefix !== '' ? $prefix . '/' . $suffix : $suffix;
}

function recordMessage(string $topic, string $payload): array
{
    return [
        'topic' => $topic,
        'payload' => $payload,
        'decoded' => json_decode($payload, true),
        'receivedAt' => microtime(true),
    ];
}

function clearMessages(array &$messages): void
{
    $messages = [];
}

function drainLoop(MqttClient $client, float $seconds): void
{
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        try {
            $client->loopOnce(microtime(true), false, 100000);
        } catch (\Throwable) {
            break;
        }
    }
}

function waitForMessages(
    MqttClient $client,
    array &$messages,
    int $startIndex,
    float $timeoutSeconds,
    float $settleSeconds,
    string $command,
    array $expectedReplyTypes
): array {
    $deadline = microtime(true) + $timeoutSeconds;
    $lastCount = count($messages);
    $lastChange = microtime(true);

    while (microtime(true) < $deadline) {
        try {
            $client->loopOnce(microtime(true), false, 100000);
        } catch (\Throwable) {
            break;
        }

        $currentCount = count($messages);
        if ($currentCount > $lastCount) {
            $lastCount = $currentCount;
            $lastChange = microtime(true);
        }

        $captured = array_slice($messages, $startIndex);
        $hasReply = false;
        foreach ($captured as $message) {
            if (isMatchingNativeReply($message, $command, $expectedReplyTypes) || isExpectedDecodedReply($message, $expectedReplyTypes)) {
                $hasReply = true;
                break;
            }
        }
        if (($hasReply || downlinkState($captured) !== null) && (microtime(true) - $lastChange) >= $settleSeconds) {
            break;
        }
    }

    return array_slice($messages, $startIndex);
}

function prettyPayload(string $payload): string
{
    $decoded = json_decode($payload, true);
    if (!is_array($decoded)) {
        return $payload;
    }

    return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $payload;
}

function visibleMessages(array $messages, bool $showRaw): array
{
    if ($showRaw) {
        return $messages;
    }

    return array_values(array_filter($messages, static function (array $message): bool {
        return !str_ends_with((string)($message['topic'] ?? ''), '/raw');
    }));
}

function highlightTopic(string $topic): string
{
    if (function_exists('stream_isatty') && @stream_isatty(STDOUT)) {
        return "\033[36m{$topic}\033[0m";
    }

    return $topic;
}

function isDeviceReply(array $message): bool
{
    $topic = (string)($message['topic'] ?? '');
    $decoded = $message['decoded'] ?? null;

    if (str_ends_with($topic, '/raw')) {
        return is_array($decoded) && (($decoded['direction'] ?? '') !== 'downlink');
    }

    return false;
}

function isMatchingNativeReply(array $message, string $command, array $expectedReplyTypes = []): bool
{
    $native = nativePayload($message);
    if ($native === null) {
        return false;
    }

    $type = (string)($native['type'] ?? '');
    if ($type === $command && (string)($native['ref'] ?? '') === 'w:reply') {
        return true;
    }

    return $type !== '' && in_array($type, $expectedReplyTypes, true);
}

function isExpectedDecodedReply(array $message, array $expectedReplyTypes): bool
{
    $topic = (string)($message['topic'] ?? '');
    if (!str_ends_with($topic, '/telemetry')) {
        return false;
    }

    $decoded = $message['decoded'] ?? null;
    if (!is_array($decoded)) {
        return false;
    }

    $nativeType = (string)($decoded['source']['nativeType'] ?? '');
    return $nativeType !== '' && in_array($nativeType, $expectedReplyTypes, true);
}

function downlinkState(array $messages): ?array
{
    $state = null;
    foreach ($messages as $message) {
        $decoded = $message['decoded'] ?? null;
        if (!is_array($decoded)) {
            continue;
        }

        $type = (string)($decoded['type'] ?? '');
        if (!in_array($type, ['device.downlink.queued', 'device.downlink.sent', 'device.downlink.dropped'], true)) {
            continue;
        }

        $state = [
            'type' => $type,
            'error' => is_array($decoded['error'] ?? null) ? $decoded['error'] : null,
            'command' => is_array($decoded['command'] ?? null) ? $decoded['command'] : null,
        ];
    }

    return $state;
}

function describeDownlinkState(array $state): string
{
    $type = (string)($state['type'] ?? '');
    $command = $state['command'] ?? [];
    $nativeType = is_array($command) ? (string)($command['nativeType'] ?? '') : '';
    $suffix = $nativeType !== '' ? " {$nativeType}" : '';

    return match ($type) {
        'device.downlink.queued' => "[queued]{$suffix} accepted by hub",
        'device.downlink.sent' => "[sent]{$suffix} delivered to device connection",
        'device.downlink.dropped' => "[dropped]{$suffix} " . (string)($state['error']['code'] ?? 'unknown_error'),
        default => '[unknown] downlink state unavailable',
    };
}

function nativePayload(array $message): ?array
{
    if (!isDeviceReply($message)) {
        return null;
    }

    $encoded = $message['decoded']['debug']['payload'] ?? null;
    if (!is_string($encoded) || $encoded === '') {
        return null;
    }

    $bytes = base64_decode($encoded, true);
    if (!is_string($bytes) || strlen($bytes) < 4) {
        return null;
    }

    $header = @unpack('nstart/nlength', substr($bytes, 0, 4));
    if (($header['start'] ?? null) !== 0xFCAF) {
        return null;
    }

    $json = substr($bytes, 4, (int)($header['length'] ?? 0));
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : null;
}

function indent(string $text, string $prefix = '    '): string
{
    return $prefix . str_replace("\n", "\n" . $prefix, $text);
}
