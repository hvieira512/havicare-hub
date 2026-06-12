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
    ));

if ($commandFilter !== '' && $selected === []) {
    fwrite(STDERR, "Unknown Wonlex command: {$commandFilter}\n");
    exit(1);
}

if ($selected === []) {
    fwrite(STDERR, "No commands selected. Use --include-risk normal,high or --command dnHeartRate.\n");
    exit(1);
}

$clientId = substr('health-wonlex-tester-' . getmypid() . '-' . bin2hex(random_bytes(4)), 0, 23);
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
$rawTopic = topic($topicPrefix, "devices/$imei/raw");
$downlinkTopic = topic($topicPrefix, "devices/$imei/downlink");

$messages = [];
$client->subscribe($eventsTopic, static function (string $topic, string $payload) use (&$messages): void {
    $messages[] = recordMessage($topic, $payload);
}, MqttClient::QOS_AT_MOST_ONCE);

$client->subscribe($rawTopic, static function (string $topic, string $payload) use (&$messages): void {
    $messages[] = recordMessage($topic, $payload);
}, MqttClient::QOS_AT_MOST_ONCE);

echo "Connected to {$host}:{$port}" . PHP_EOL;
echo "Watching: {$eventsTopic}" . PHP_EOL;
echo "Watching: {$rawTopic}" . PHP_EOL;
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

    $captured = waitForMessages($client, $messages, $before, $timeoutSeconds, $settleSeconds);
    if ($captured === []) {
        echo "  [timeout] no MQTT response within {$timeoutSeconds}s" . PHP_EOL . PHP_EOL;
        continue;
    }

    foreach ($captured as $message) {
        echo '  ' . highlightTopic($message['topic']) . PHP_EOL;
        echo indent(prettyPayload($message['payload'])) . PHP_EOL;
    }

    $replyMessages = array_values(array_filter($captured, static fn(array $message): bool => isDeviceReply($message)));
    $decodedReplies = array_values(array_filter($captured, static fn(array $message): bool => isDecodedReply($message)));
    if ($replyMessages === [] && $decodedReplies === []) {
        echo "  [sent] downlink accepted by hub, but no device reply observed" . PHP_EOL;
    } else {
        echo "  [ok] device replied with " . count($replyMessages) . " raw message(s) and " . count($decodedReplies) . " decoded event(s)" . PHP_EOL;
    }

    echo PHP_EOL;
}

$client->disconnect();

function usage(): void
{
    echo <<<TXT
Usage:
  php simulator/wonlex-tester.php --imei IMEI --host HOST --port PORT --username USER --password PASS [options]

Options:
  --command dnHeartRate   Run a single command instead of the full set.
  --payload JSON          Override the payload object sent for every command.
  --include-risk normal,high
                          Select which commands to run. Default: normal.
  --timeout 8             Maximum seconds to wait for replies per command.
  --settle 1.0            Stop early after this many quiet seconds.
  --topic-prefix PREFIX    MQTT topic prefix. Default: hitecosystem-hub
  --list-commands         Print server downlinks and device uplinks, then exit.

Notes:
  - Replies are read from devices/{imei}/events and devices/{imei}/raw.
  - Commands listed under "server -> device" can be used with --command.
  - Commands listed under "device -> server" are native uplinks the device may send.
  - The destructive reset/restart/powerOff commands are behind --include-risk high.
  - This tester publishes base64-encoded Wonlex JSON frames to devices/{imei}/downlink.

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
        ['command' => 'dnECGAnalysis', 'title' => 'Request ECG analysis', 'kind' => 'request', 'risk' => 'normal', 'expectedReplyTypes' => ['upECGAnalysis']],
        ['command' => 'dnHRV', 'title' => 'Request HRV', 'kind' => 'request', 'risk' => 'normal', 'expectedReplyTypes' => ['upHRV']],
        ['command' => 'dnPPG', 'title' => 'Request PPG', 'kind' => 'request', 'risk' => 'normal', 'expectedReplyTypes' => ['upPPG']],
        ['command' => 'dnRR', 'title' => 'Request RR interval', 'kind' => 'request', 'risk' => 'normal', 'expectedReplyTypes' => ['upRR']],
        ['command' => 'dnLocation', 'title' => 'Request location', 'kind' => 'request', 'risk' => 'normal', 'expectedReplyTypes' => ['upLocation']],
        ['command' => 'locationInterval', 'title' => 'Set location interval', 'kind' => 'config', 'risk' => 'normal', 'expectedReplyTypes' => ['upDeviceConfig']],
        ['command' => 'deviceConfig', 'title' => 'Set device config', 'kind' => 'config', 'risk' => 'normal', 'expectedReplyTypes' => ['upDeviceConfig']],
        ['command' => 'alarmClock', 'title' => 'Set alarm clock', 'kind' => 'config', 'risk' => 'normal', 'expectedReplyTypes' => ['upDeviceConfig']],
        ['command' => 'SOSNumber', 'title' => 'Set SOS numbers', 'kind' => 'config', 'risk' => 'normal', 'expectedReplyTypes' => ['upDeviceConfig']],
        ['command' => 'dnUpSleep', 'title' => 'Request sleep report', 'kind' => 'request', 'risk' => 'normal', 'expectedReplyTypes' => ['upSleep']],
        ['command' => 'dnWeather', 'title' => 'Request weather', 'kind' => 'request', 'risk' => 'normal', 'expectedReplyTypes' => ['upWeather']],
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

function buildDownlinkPayload(string $imei, string $command, string $requestId, string $ident, ?array $payloadOverride): array
{
    $timestamp = (int)round(microtime(true) * 1000);
    $data = [
        'type' => $command,
        'imei' => $imei,
        'timestamp' => $timestamp,
    ];

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

function randomIdent(): string
{
    return (string)random_int(100000, 999999);
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
    float $settleSeconds
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
            if (isDeviceReply($message) || isDecodedReply($message)) {
                $hasReply = true;
                break;
            }
        }
        if ($hasReply && (microtime(true) - $lastChange) >= $settleSeconds) {
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

function isDecodedReply(array $message): bool
{
    $topic = (string)($message['topic'] ?? '');
    if (!str_ends_with($topic, '/events')) {
        return false;
    }

    $decoded = $message['decoded'] ?? null;
    return is_array($decoded) && (string)($decoded['type'] ?? '') === 'device.data.received';
}

function indent(string $text, string $prefix = '    '): string
{
    return $prefix . str_replace("\n", "\n" . $prefix, $text);
}
