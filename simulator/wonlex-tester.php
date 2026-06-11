#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Protocol\Adapter\WonlexAdapter;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

$args = parseArgs($argv);

if (isset($args['help']) || isset($args['h'])) {
    usage();
    exit(0);
}

$imei = (string)($args['imei'] ?? '');
if ($imei === '') {
    usage();
    exit(1);
}

$host = (string)($args['host'] ?? '127.0.0.1');
$port = (int)($args['port'] ?? 1883);
$username = (string)($args['username'] ?? '');
$password = (string)($args['password'] ?? '');
$topicPrefix = trim((string)($args['topic-prefix'] ?? 'hitecosystem-hub'), '/');
$timeoutSeconds = max(0.5, (float)($args['timeout'] ?? 8.0));
$settleSeconds = max(0.1, (float)($args['settle'] ?? 1.0));
$commandFilter = trim((string)($args['command'] ?? ''));
$riskFilter = parseCsv((string)($args['include-risk'] ?? 'normal'));
$payloadOverride = isset($args['payload']) ? decodeJsonObject((string)$args['payload']) : null;
$listCommands = isset($args['list-commands']);

$adapter = new WonlexAdapter();
$manifest = wonlexCommands();

if ($listCommands) {
    foreach ($manifest as $entry) {
        $risk = $entry['risk'] ?? 'normal';
        $expected = $entry['expectedReplyTypes'] ?? [];
        echo $entry['command'] . "\t" . ($entry['title'] ?? '') . "\t[" . $risk . "]\t" . implode(',', $expected) . PHP_EOL;
    }
    exit(0);
}

$selected = $commandFilter !== ''
    ? array_values(array_filter($manifest, static fn (array $entry): bool => $entry['command'] === $commandFilter))
    : array_values(array_filter(
        $manifest,
        static fn (array $entry): bool => in_array((string)($entry['risk'] ?? 'normal'), $riskFilter, true)
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

$client->connect($settings, true);

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

    $replyMessages = array_values(array_filter($captured, static fn (array $message): bool => isDeviceReply($message)));
    $decodedReplies = array_values(array_filter($captured, static fn (array $message): bool => isDecodedReply($message)));
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
  --list-commands         Print the Wonlex command catalog and exit.

Notes:
  - Replies are read from devices/{imei}/events and devices/{imei}/raw.
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
    $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
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
        ['command' => 'dnHeartRate', 'title' => 'Request heart rate', 'risk' => 'normal', 'expectedReplyTypes' => ['upHeartRate', 'upBatch']],
        ['command' => 'dnBP', 'title' => 'Request blood pressure', 'risk' => 'normal', 'expectedReplyTypes' => ['upBP', 'upBatch']],
        ['command' => 'dnBO', 'title' => 'Request blood oxygen', 'risk' => 'normal', 'expectedReplyTypes' => ['upBO', 'upBatch']],
        ['command' => 'dnTemperature', 'title' => 'Request temperature', 'risk' => 'normal', 'expectedReplyTypes' => ['upBodyTemperature']],
        ['command' => 'dnBreathe', 'title' => 'Request respiration', 'risk' => 'normal', 'expectedReplyTypes' => ['upBreathe']],
        ['command' => 'dnECG', 'title' => 'Request ECG', 'risk' => 'normal', 'expectedReplyTypes' => ['upECG']],
        ['command' => 'dnECGAnalysis', 'title' => 'Request ECG analysis', 'risk' => 'normal', 'expectedReplyTypes' => ['upECGAnalysis']],
        ['command' => 'dnHRV', 'title' => 'Request HRV', 'risk' => 'normal', 'expectedReplyTypes' => ['upHRV']],
        ['command' => 'dnPPG', 'title' => 'Request PPG', 'risk' => 'normal', 'expectedReplyTypes' => ['upPPG']],
        ['command' => 'dnRR', 'title' => 'Request RR interval', 'risk' => 'normal', 'expectedReplyTypes' => ['upRR']],
        ['command' => 'dnLocation', 'title' => 'Request location', 'risk' => 'normal', 'expectedReplyTypes' => ['upLocation']],
        ['command' => 'locationInterval', 'title' => 'Set location interval', 'risk' => 'normal', 'expectedReplyTypes' => ['upDeviceConfig']],
        ['command' => 'deviceConfig', 'title' => 'Set device config', 'risk' => 'normal', 'expectedReplyTypes' => ['upDeviceConfig']],
        ['command' => 'alarmClock', 'title' => 'Set alarm clock', 'risk' => 'normal', 'expectedReplyTypes' => ['upDeviceConfig']],
        ['command' => 'SOSNumber', 'title' => 'Set SOS numbers', 'risk' => 'normal', 'expectedReplyTypes' => ['upDeviceConfig']],
        ['command' => 'dnUpSleep', 'title' => 'Request sleep report', 'risk' => 'normal', 'expectedReplyTypes' => ['upSleep']],
        ['command' => 'dnWeather', 'title' => 'Request weather', 'risk' => 'normal', 'expectedReplyTypes' => ['upWeather']],
        ['command' => 'dnMedicationPlan', 'title' => 'Set medication plan', 'risk' => 'normal', 'expectedReplyTypes' => ['upDeviceConfig']],
        ['command' => 'findPhoneBillOrFlow', 'title' => 'Request phone bill or flow', 'risk' => 'normal', 'expectedReplyTypes' => []],
        ['command' => 'find', 'title' => 'Find device', 'risk' => 'normal', 'expectedReplyTypes' => []],
        ['command' => 'OTA', 'title' => 'OTA update', 'risk' => 'high', 'expectedReplyTypes' => ['upGetOTA']],
        ['command' => 'reset', 'title' => 'Factory reset', 'risk' => 'high', 'expectedReplyTypes' => ['upReset']],
        ['command' => 'restart', 'title' => 'Restart device', 'risk' => 'high', 'expectedReplyTypes' => ['upShutdown']],
        ['command' => 'powerOff', 'title' => 'Power off device', 'risk' => 'high', 'expectedReplyTypes' => ['upShutdown']],
    ];
}

function buildDownlinkPayload(string $imei, string $command, string $requestId, string $ident, ?array $payloadOverride): array
{
    return [
        'type' => $command,
        'ident' => $ident,
        'ref' => 's:down',
        'imei' => $imei,
        'data' => $payloadOverride ?? [],
        'requestId' => $requestId,
        'timestamp' => (int)round(microtime(true) * 1000),
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

        if ($currentCount > $startIndex && (microtime(true) - $lastChange) >= $settleSeconds) {
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
