#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

$args = parseArgs($argv);
$server = (string)($args['server'] ?? 'tcp://127.0.0.1:9000');
$model = (string)($args['model'] ?? 'VIVISTAR-CARE');
$protocolOverride = (string)($args['protocol'] ?? '');
$imei = (string)($args['imei'] ?? '');
$command = (string)($args['command'] ?? '');
$payloadOverride = isset($args['payload']) ? decodeJsonObject((string)$args['payload']) : null;
$listen = isset($args['listen']);

if ($imei === '') {
    fwrite(STDERR, "Usage: php simulator/simulate.php --imei IMEI [--model MODEL] [--server URL] [--command RAW_OR_TYPE] [--payload JSON] [--listen]\n");
    exit(1);
}

$protocol = $protocolOverride !== '' ? $protocolOverride : protocolForModel($model);
if (!in_array($protocol, ['vivistar-iw', 'wonlex-json'], true)) {
    fwrite(STDERR, "Unsupported protocol: {$protocol}. Use vivistar-iw or wonlex-json.\n");
    exit(1);
}
$client = new TcpTextClient($server);

sendProtocolPacket($client, $protocol, loginPayload($protocol, $imei, $model));
$reply = receiveProtocolPacket($client, $protocol, 5);
if ($reply !== null) {
    echo '[LOGIN_REPLY] ' . json_encode($reply, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

if ($command !== '') {
    sendProtocolPacket($client, $protocol, commandPayload($protocol, $imei, $model, $command, $payloadOverride));
}

if (!$listen) {
    exit(0);
}

while (true) {
    $packet = receiveProtocolPacket($client, $protocol, 30);
    if ($packet === null) {
        continue;
    }

    $type = (string)($packet['type'] ?? '');
    echo "[COMMAND] $type " . json_encode($packet, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

    if ($protocol === 'vivistar-iw' && str_starts_with($type, 'BP')) {
        $replyType = 'AP' . substr($type, 2);
        $ident = (string)($packet['fields'][1] ?? $packet['fields'][0] ?? '123456');
        sendProtocolPacket($client, $protocol, "IW{$replyType},{$ident}#");
    }
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

function decodeJsonObject(string $value): array
{
    $decoded = json_decode($value, true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "Failed to decode --payload JSON\n");
        exit(1);
    }

    return $decoded;
}

function protocolForModel(string $model): string
{
    $model = strtoupper($model);
    return str_starts_with($model, 'VIVISTAR') || str_starts_with($model, 'VL') ? 'vivistar-iw' : 'wonlex-json';
}

function loginPayload(string $protocol, string $imei, string $model): array|string
{
    if ($protocol === 'vivistar-iw') {
        return "IWAP00{$imei}#";
    }

    return [
        'type' => 'login',
        'ident' => bin2hex(random_bytes(4)),
        'ref' => 'w:update',
        'imei' => $imei,
        'data' => ['deviceModel' => $model],
        'timestamp' => nowMs(),
    ];
}

function commandPayload(string $protocol, string $imei, string $model, string $command, ?array $payloadOverride = null): array|string
{
    if ($protocol === 'vivistar-iw') {
        if (str_starts_with($command, 'IW')) {
            $command = preg_replace('/\s+/', '', $command) ?? $command;
            return str_ends_with($command, '#') ? $command : $command . '#';
        }
        return match ($command) {
            'AP49' => 'IWAP49,72#',
            'APXL' => 'IWAPXL,123456#',
            default => "IW{$command}#",
        };
    }

    $type = $command !== '' ? $command : 'upBattery';
    $data = wonlexSampleData($type);
    $data = array_replace([
        'type' => $type,
        'imei' => $imei,
        'deviceModel' => $model,
    ], $data);
    if ($payloadOverride !== null) {
        $data = array_replace($data, $payloadOverride);
    }

    return [
        'type' => $type,
        'ident' => bin2hex(random_bytes(4)),
        'ref' => 'w:update',
        'imei' => $imei,
        'data' => $data,
        'timestamp' => nowMs(),
    ];
}

function wonlexSampleData(string $type): array
{
    return match ($type) {
        'heartbeat' => [
            'batteryLevel' => 90,
            'batteryState' => 0,
        ],
        'upHeartRate' => [
            'data' => '72',
            'testType' => 2,
        ],
        'upBP' => [
            'data' => '120/80/72',
            'testType' => 2,
        ],
        'upBO' => [
            'data' => '98',
            'testType' => 2,
        ],
        'upBodyTemperature' => [
            'data' => '36.6/31.0/27.8',
            'testType' => 2,
        ],
        'upBattery' => [
            'batteryLevel' => 90,
            'batteryState' => 0,
            'batteryType' => 2,
        ],
        'upLocation' => [
            'baseStationType' => 0,
            'positionDataType' => '1',
            'gps' => [
                'lat' => '38.7150',
                'lon' => '-9.1450',
                'height' => 45,
                'satelliteNum' => 8,
                'GSM' => 90,
                'Type' => 0,
            ],
            'baseStation' => [
                ['mcc' => 268, 'mnc' => 1, 'lac' => 1234, 'ci' => 5679, 'rxlev' => 49],
            ],
            'wifi' => [
                ['ssid' => 'HOME', 'mac' => 'AA:BB:CC:DD:EE:FF', 'signal' => '-58'],
            ],
        ],
        'upBatch' => [
            'heartRate' => '100,98,97',
            'bp' => '120/80/72',
            'bo' => '98',
            'testType' => 2,
        ],
        'upTodayActivity' => [
            'step' => 3120,
            'exerciseTime' => 1800,
            'standTime' => 6,
        ],
        'upSleep' => [
            'value' => '420/120/280/20',
            'upDayStr' => gmdate('Y-m-d'),
        ],
        default => [],
    };
}

function sendProtocolPacket(TcpTextClient $client, string $protocol, array|string $payload): void
{
    if ($protocol === 'vivistar-iw') {
        $line = is_string($payload) ? $payload : '';
        $client->send($line);
        return;
    }

    if (!is_array($payload)) {
        throw new RuntimeException('Wonlex payload must be an array');
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $frame = pack('nn', 0xFCAF, strlen($json)) . $json;
    $client->send($frame);
}

function receiveProtocolPacket(TcpTextClient $client, string $protocol, ?int $timeout = null): ?array
{
    $raw = $protocol === 'wonlex-json'
        ? $client->receiveFrame($timeout)
        : $client->receive($timeout);
    if ($raw === null || $raw === '') {
        return null;
    }

    if ($protocol === 'vivistar-iw') {
        return parseVivistarLine($raw);
    }

    if (strlen($raw) < 4) {
        return null;
    }

    $header = unpack('nstart/nlength', substr($raw, 0, 4));
    if (($header['start'] ?? null) !== 0xFCAF) {
        return null;
    }
    $decoded = json_decode(substr($raw, 4, (int)$header['length']), true);
    return is_array($decoded) ? $decoded : null;
}

function parseVivistarLine(string $line): ?array
{
    $line = trim($line);
    if (!str_starts_with($line, 'IW') || !str_ends_with($line, '#')) {
        return null;
    }

    $body = substr($line, 2, -1);
    if (preg_match('/^([A-Z]{2}[A-Z0-9]{2})(.*)$/', $body, $m) !== 1) {
        return null;
    }

    $tail = ltrim((string)($m[2] ?? ''), ',');
    return [
        'type' => $m[1],
        'fields' => $tail === '' ? [] : explode(',', $tail),
        'raw' => $line,
    ];
}

function nowMs(): int
{
    return (int)round(microtime(true) * 1000);
}

class TcpTextClient
{
    private $socket;
    private string $buffer = '';

    public function __construct(string $url)
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '127.0.0.1';
        $port = (int)($parts['port'] ?? 9000);
        $this->socket = @fsockopen($host, $port, $errno, $errstr, 5);
        if (!$this->socket) {
            throw new RuntimeException("Failed to connect: $errstr ($errno)");
        }
    }

    public function send(string $data): void
    {
        fwrite($this->socket, $data);
    }

    public function receive(?int $timeout = null): ?string
    {
        stream_set_timeout($this->socket, $timeout ?? 0);
        while (true) {
            $pos = strpos($this->buffer, '#');
            if ($pos !== false) {
                $packet = substr($this->buffer, 0, $pos + 1);
                $this->buffer = substr($this->buffer, $pos + 1);
                return trim($packet);
            }

            $chunk = fread($this->socket, 1024);
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $this->buffer .= $chunk;
        }
    }

    public function receiveFrame(?int $timeout = null): ?string
    {
        stream_set_timeout($this->socket, $timeout ?? 0);
        $header = $this->readBytes(4);
        if ($header === null) {
            return null;
        }

        $fields = unpack('nstart/nlength', $header);
        if (($fields['start'] ?? null) !== 0xFCAF) {
            return null;
        }

        $length = (int)($fields['length'] ?? 0);
        $payload = $length > 0 ? $this->readBytes($length) : '';
        if ($payload === null) {
            return null;
        }

        return $header . $payload;
    }

    private function readBytes(int $length): ?string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($this->socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $data .= $chunk;
        }

        return $data;
    }
}
