#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Bootstrap;
use App\Config;
use App\Protocol\Adapter\VivistarAdapter;
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
$timeoutSeconds = max(0.5, (float)($args['timeout'] ?? 6.0));
$settleSeconds = max(0.1, (float)($args['settle'] ?? 0.6));
$commandIdent = (string)($args['ident'] ?? '080835');
$commandFilter = trim((string)($args['command'] ?? ''));
$riskFilter = parseCsv((string)($args['include-risk'] ?? 'normal'));
$fieldsOverride = isset($args['fields']) ? parseCsv((string)$args['fields']) : null;
$listCommands = isset($args['list-commands']);
$showRaw = isset($args['show-raw']);

$adapter = new VivistarAdapter();
$manifest = vivistarCommands();

if ($listCommands) {
    printCommandCatalog($manifest, vivistarUplinks());
    exit(0);
}

$selected = $commandFilter !== ''
    ? array_values(array_filter($manifest, static fn (array $entry): bool => $entry['command'] === $commandFilter))
    : array_values(array_filter(
        $manifest,
        static fn (array $entry): bool => in_array((string)($entry['risk'] ?? 'normal'), $riskFilter, true)
            && (string)($entry['kind'] ?? 'request') === 'request'
    ));

if ($commandFilter !== '' && $selected === []) {
    fwrite(STDERR, "Unknown Vivistar command: {$commandFilter}\n");
    exit(1);
}

if ($selected === []) {
    fwrite(STDERR, "No commands selected. Use --include-risk normal,high or --command BPXL.\n");
    exit(1);
}

$clientId = substr('health-vivistar-tester-' . getmypid() . '-' . bin2hex(random_bytes(4)), 0, 23);
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

    $payload = buildDownlinkPayload($adapter, $imei, $command, $commandIdent, $fieldsOverride ?? ($entry['data'] ?? []));
    $before = count($messages);

    echo "[SEND] {$command} {$title}" . PHP_EOL;
    if (!empty($replyTypes)) {
        echo "       expected replies: " . implode(', ', $replyTypes) . PHP_EOL;
    }
    echo "       payload: {$payload}" . PHP_EOL;

    $client->publish(
        $downlinkTopic,
        json_encode([
            'encoding' => 'text',
            'payload' => $payload,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        MqttClient::QOS_AT_MOST_ONCE,
        false
    );

    $captured = waitForMessages($client, $messages, $before, $timeoutSeconds, $settleSeconds);
    if ($captured === []) {
        echo "  [timeout] no MQTT response within {$timeoutSeconds}s" . PHP_EOL . PHP_EOL;
        continue;
    }

    foreach (visibleMessages($captured, $showRaw) as $message) {
        echo '  ' . highlightTopic($message['topic']) . PHP_EOL;
        echo indent(prettyPayload($message['payload'])) . PHP_EOL;
    }

    $replyMessages = array_values(array_filter($captured, static fn (array $message): bool => isDeviceReply($message)));
    $decodedReplies = array_values(array_filter($captured, static fn (array $message): bool => isDecodedReply($message)));
    if ($replyMessages === [] && $decodedReplies === []) {
        echo "  [sent] downlink accepted by hub, but no device reply observed" . PHP_EOL;
    } elseif ($decodedReplies === [] && !$showRaw) {
        echo "  [ok] device replied with " . count($replyMessages) . " native raw message(s), but no decoded event was produced. Use --show-raw to inspect." . PHP_EOL;
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
  php simulator/vivistar-tester.php --imei IMEI --host HOST --port PORT --username USER --password PASS [options]

Options:
  --command BPXL           Run a single command instead of the full set.
  --ident 080835           Command ident field to embed in Vivistar downlinks.
  --fields A,B,C           Override command data fields, e.g. --fields +351938854803 for BP12.
  --include-risk normal,high
                           Select risk level for bulk request runs. Default: normal.
  --timeout 6              Maximum seconds to wait for replies per command.
  --settle 0.6             Stop early after this many quiet seconds.
  --topic-prefix PREFIX     MQTT topic prefix, if the broker uses one. Default: hitecosystem-hub
  --show-raw               Print raw MQTT packets. By default raw is used only to detect device replies.
  --list-commands          Print server downlinks and device uplinks, then exit.

Notes:
  - Replies are read from devices/{imei}/events and devices/{imei}/raw.
  - Commands listed under "server -> device" can be used with --command.
  - Bulk runs only send request commands; use --command to send config/control commands explicitly.
  - Commands listed under "device -> server" are native uplinks the device may send.
  - The destructive BP17 factory-reset command is behind --include-risk high.
  - This tester publishes native downlink frames to devices/{imei}/downlink.

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

function vivistarCommands(): array
{
    return [
        [
            'command' => 'BP12',
            'title' => 'Set SOS contacts',
            'kind' => 'config',
            'risk' => 'normal',
            'expectedReplyTypes' => ['AP12'],
            'data' => ['13500000001', '13500000002', '13500000003'],
        ],
        [
            'command' => 'BP14',
            'title' => 'Set contacts',
            'kind' => 'config',
            'risk' => 'normal',
            'expectedReplyTypes' => ['AP14'],
        ],
        [
            'command' => 'BP16',
            'title' => 'Request location now',
            'kind' => 'request',
            'risk' => 'normal',
            'expectedReplyTypes' => ['AP16', 'AP01'],
        ],
        [
            'command' => 'BP17',
            'title' => 'Factory reset',
            'kind' => 'control',
            'risk' => 'high',
            'expectedReplyTypes' => ['AP17'],
        ],
        [
            'command' => 'BP28',
            'title' => 'Push short message',
            'kind' => 'control',
            'risk' => 'normal',
            'expectedReplyTypes' => ['AP28'],
        ],
        [
            'command' => 'BP33',
            'title' => 'Set device config',
            'kind' => 'config',
            'risk' => 'normal',
            'expectedReplyTypes' => ['AP33'],
        ],
        [
            'command' => 'BP40',
            'title' => 'Push message variant',
            'kind' => 'control',
            'risk' => 'normal',
            'expectedReplyTypes' => ['AP40'],
            'data' => ['00610072006500200079006f00750020006f006b003f'],
        ],
        [
            'command' => 'BP76',
            'title' => 'Set fall detection',
            'kind' => 'config',
            'risk' => 'normal',
            'expectedReplyTypes' => ['AP76'],
        ],
        [
            'command' => 'BP77',
            'title' => 'Set fall detection emergency',
            'kind' => 'config',
            'risk' => 'normal',
            'expectedReplyTypes' => ['AP77'],
        ],
        [
            'command' => 'BP84',
            'title' => 'Set quick contacts',
            'kind' => 'config',
            'risk' => 'normal',
            'expectedReplyTypes' => ['AP84'],
        ],
        [
            'command' => 'BP85',
            'title' => 'Set reminders',
            'kind' => 'config',
            'risk' => 'normal',
            'expectedReplyTypes' => ['AP85'],
        ],
        [
            'command' => 'BP86',
            'title' => 'Set config variant',
            'kind' => 'config',
            'risk' => 'normal',
            'expectedReplyTypes' => ['AP86'],
        ],
        [
            'command' => 'BP87',
            'title' => 'Request temperature variant',
            'kind' => 'request',
            'risk' => 'normal',
            'expectedReplyTypes' => ['AP87'],
        ],
        [
            'command' => 'BPJZ',
            'title' => 'Blood pressure calibration',
            'kind' => 'config',
            'risk' => 'normal',
            'expectedReplyTypes' => ['APJZ'],
        ],
        [
            'command' => 'BPXL',
            'title' => 'Request heart rate',
            'kind' => 'request',
            'risk' => 'normal',
            'expectedReplyTypes' => ['APXL'],
        ],
        [
            'command' => 'BPXY',
            'title' => 'Request blood pressure',
            'kind' => 'request',
            'risk' => 'normal',
            'expectedReplyTypes' => ['APXY'],
        ],
        [
            'command' => 'BPXT',
            'title' => 'Request temperature',
            'kind' => 'request',
            'risk' => 'normal',
            'expectedReplyTypes' => ['APXT'],
        ],
        [
            'command' => 'BPXZ',
            'title' => 'Request blood oxygen',
            'kind' => 'request',
            'risk' => 'normal',
            'expectedReplyTypes' => ['APXZ'],
        ],
    ];
}

function vivistarUplinks(): array
{
    return [
        ['command' => 'AP00', 'origin' => 'device-init', 'features' => ['status'], 'responds' => 'BP00', 'notes' => 'Login packet sent when the device opens a TCP session.'],
        ['command' => 'AP01', 'origin' => 'scheduled/request', 'features' => ['location'], 'responds' => 'BP01', 'notes' => 'GPS/LBS/Wi-Fi location packet; may be sent after BP16.'],
        ['command' => 'AP02', 'origin' => 'scheduled/request', 'features' => ['location'], 'responds' => 'BP02', 'notes' => 'Multi-base-station location packet.'],
        ['command' => 'AP03', 'origin' => 'scheduled', 'features' => ['heartbeat', 'battery', 'activity'], 'responds' => 'BP03', 'notes' => 'Heartbeat used to keep the long TCP connection alive.'],
        ['command' => 'AP07', 'origin' => 'device-init', 'features' => ['audio'], 'responds' => 'BP07', 'notes' => 'Upload audio message.'],
        ['command' => 'AP10', 'origin' => 'alarm', 'features' => ['alarm', 'location'], 'responds' => 'BP10', 'notes' => 'Alarm and return-address packet.'],
        ['command' => 'AP49', 'origin' => 'scheduled/request/manual', 'features' => ['heart_rate'], 'responds' => 'BP49', 'notes' => 'Heart-rate upload.'],
        ['command' => 'APHT', 'origin' => 'scheduled/request/manual', 'features' => ['heart_rate', 'blood_pressure'], 'responds' => 'BPHT', 'notes' => 'Heart-rate and blood-pressure upload.'],
        ['command' => 'APHP', 'origin' => 'scheduled/request/manual', 'features' => ['heart_rate', 'blood_pressure', 'blood_oxygen', 'blood_sugar'], 'responds' => 'BPHP', 'notes' => 'Combined health upload.'],
        ['command' => 'AP50', 'origin' => 'scheduled/request/manual', 'features' => ['temperature', 'battery'], 'responds' => 'BP50', 'notes' => 'Body-temperature upload with battery.'],
        ['command' => 'AP12', 'origin' => 'config-reply', 'features' => ['device_config'], 'responds' => '-', 'notes' => 'Reply to BP12.'],
        ['command' => 'AP14', 'origin' => 'config-reply', 'features' => ['device_config'], 'responds' => '-', 'notes' => 'Reply to BP14.'],
        ['command' => 'AP16', 'origin' => 'request-reply', 'features' => ['device_config'], 'responds' => '-', 'notes' => 'Acknowledges BP16; location may follow as AP01.'],
        ['command' => 'AP17', 'origin' => 'control-reply', 'features' => ['device_config'], 'responds' => '-', 'notes' => 'Reply to BP17 factory reset.'],
        ['command' => 'AP28', 'origin' => 'control-reply', 'features' => ['device_config'], 'responds' => '-', 'notes' => 'Reply to BP28.'],
        ['command' => 'AP33', 'origin' => 'config-reply', 'features' => ['device_config'], 'responds' => '-', 'notes' => 'Reply to BP33 working mode/config.'],
        ['command' => 'AP40', 'origin' => 'control-reply', 'features' => ['device_config'], 'responds' => '-', 'notes' => 'Reply to BP40.'],
        ['command' => 'AP76', 'origin' => 'config-reply', 'features' => ['device_config'], 'responds' => '-', 'notes' => 'Reply to BP76.'],
        ['command' => 'AP77', 'origin' => 'config-reply', 'features' => ['device_config'], 'responds' => '-', 'notes' => 'Reply to BP77.'],
        ['command' => 'AP84', 'origin' => 'config-reply', 'features' => ['device_config'], 'responds' => '-', 'notes' => 'Reply to BP84.'],
        ['command' => 'AP85', 'origin' => 'config-reply', 'features' => ['device_config'], 'responds' => '-', 'notes' => 'Reply to BP85.'],
        ['command' => 'AP86', 'origin' => 'config-reply', 'features' => ['device_config'], 'responds' => '-', 'notes' => 'Reply to BP86.'],
        ['command' => 'AP87', 'origin' => 'request-reply', 'features' => ['device_config'], 'responds' => '-', 'notes' => 'Reply to BP87.'],
        ['command' => 'APJZ', 'origin' => 'config-reply', 'features' => ['device_config'], 'responds' => '-', 'notes' => 'Reply to BPJZ.'],
        ['command' => 'APXL', 'origin' => 'request-reply', 'features' => ['device_config'], 'responds' => '-', 'notes' => 'Acknowledges BPXL; device may separately upload AP49.'],
        ['command' => 'APXY', 'origin' => 'request-reply', 'features' => ['device_config'], 'responds' => '-', 'notes' => 'Acknowledges BPXY; device may separately upload APHT/APHP.'],
        ['command' => 'APXT', 'origin' => 'request-reply', 'features' => ['device_config'], 'responds' => '-', 'notes' => 'Acknowledges BPXT; device may separately upload AP50.'],
        ['command' => 'APXZ', 'origin' => 'request-reply', 'features' => ['device_config'], 'responds' => '-', 'notes' => 'Reply to BPXZ.'],
    ];
}

function printCommandCatalog(array $downlinks, array $uplinks): void
{
    echo "Vivistar server -> device commands usable with --command" . PHP_EOL;
    echo "COMMAND   KIND      RISK    EXPECTED DEVICE UPLINKS          TITLE" . PHP_EOL;
    foreach ($downlinks as $entry) {
        printf(
            "%-9s %-9s %-7s %-32s %s" . PHP_EOL,
            (string)$entry['command'],
            (string)($entry['kind'] ?? 'request'),
            (string)($entry['risk'] ?? 'normal'),
            implode(',', $entry['expectedReplyTypes'] ?? []) ?: '-',
            (string)($entry['title'] ?? '')
        );
    }

    echo PHP_EOL;
    echo "Vivistar device -> server native uplinks" . PHP_EOL;
    echo "UPLINK   ORIGIN                    FEATURES                         RESPONDS NOTES" . PHP_EOL;
    foreach ($uplinks as $entry) {
        printf(
            "%-8s %-25s %-32s %-8s %s" . PHP_EOL,
            (string)$entry['command'],
            (string)$entry['origin'],
            implode(',', $entry['features'] ?? []),
            (string)($entry['responds'] ?? '-'),
            (string)($entry['notes'] ?? '')
        );
    }
}

function buildDownlinkPayload(VivistarAdapter $adapter, string $imei, string $command, string $ident, array $data = []): string
{
    $payload = [
        'type' => $command,
        'imei' => $imei,
        'ident' => $ident,
        'data' => $data,
    ];

    return $adapter->encodeOutgoing($payload);
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
