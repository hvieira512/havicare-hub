#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

$args = parseArgs($argv);
$server = (string)($args['server'] ?? 'ws://127.0.0.1:8080');
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
$client = str_starts_with($server, 'tcp://')
    ? new TcpTextClient($server)
    : new WsClient($server);

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

function sendProtocolPacket(WsClient|TcpTextClient $client, string $protocol, array|string $payload): void
{
    if ($protocol === 'vivistar-iw') {
        $line = is_string($payload) ? $payload : '';
        if ($client instanceof WsClient) {
            $client->send($line, 0x1);
        } else {
            $client->send($line);
        }
        return;
    }

    if (!is_array($payload)) {
        throw new RuntimeException('Wonlex payload must be an array');
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $frame = pack('nn', 0xFCAF, strlen($json)) . $json;
    if ($client instanceof TcpTextClient) {
        $client->send($frame);
    } else {
        $client->send($frame, 0x2);
    }
}

function receiveProtocolPacket(WsClient|TcpTextClient $client, string $protocol, ?int $timeout = null): ?array
{
    $raw = $protocol === 'wonlex-json' && $client instanceof TcpTextClient
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

class WsClient
{
    private $socket;

    public function __construct(string $url)
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '127.0.0.1';
        $port = (int)($parts['port'] ?? 8080);
        $path = $parts['path'] ?? '/';
        $this->socket = @fsockopen($host, $port, $errno, $errstr, 5);
        if (!$this->socket) {
            throw new RuntimeException("Failed to connect: $errstr ($errno)");
        }

        $key = base64_encode(random_bytes(16));
        fwrite($this->socket, "GET $path HTTP/1.1\r\nHost: $host:$port\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");
        $response = '';
        while (!feof($this->socket)) {
            $line = fgets($this->socket);
            if ($line === false) {
                break;
            }
            $response .= $line;
            if ($line === "\r\n") {
                break;
            }
        }
        if (!str_contains($response, '101 Switching Protocols')) {
            throw new RuntimeException("Handshake failed:\n$response");
        }
    }

    public function send(string $data, int $opcode = 0x2): void
    {
        fwrite($this->socket, $this->encodeFrame($data, $opcode));
    }

    public function receive(?int $timeout = null): ?string
    {
        stream_set_timeout($this->socket, $timeout ?? 0);
        $header = fread($this->socket, 2);
        if ($header === false || strlen($header) < 2) {
            return null;
        }

        $byte1 = ord($header[0]);
        $byte2 = ord($header[1]);
        $opcode = $byte1 & 0x0F;
        if ($opcode === 8) {
            return null;
        }

        $masked = ($byte2 & 0x80) !== 0;
        $len = $byte2 & 0x7F;
        if ($len === 126) {
            $len = unpack('n', fread($this->socket, 2))[1];
        } elseif ($len === 127) {
            $parts = unpack('N2', fread($this->socket, 8));
            $len = ($parts[1] << 32) + $parts[2];
        }

        $mask = $masked ? fread($this->socket, 4) : '';
        $payload = $len > 0 ? fread($this->socket, $len) : '';
        if ($payload === false) {
            return null;
        }

        if ($masked) {
            $out = '';
            for ($i = 0; $i < strlen($payload); $i++) {
                $out .= chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
            }
            return $out;
        }

        return $payload;
    }

    private function encodeFrame(string $data, int $opcode): string
    {
        $len = strlen($data);
        $mask = random_bytes(4);
        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= chr(ord($data[$i]) ^ ord($mask[$i % 4]));
        }

        $frame = chr(0x80 | ($opcode & 0x0F));
        if ($len < 126) {
            $frame .= chr(0x80 | $len);
        } elseif ($len < 65536) {
            $frame .= chr(0x80 | 126) . pack('n', $len);
        } else {
            $frame .= chr(0x80 | 127) . pack('NN', 0, $len);
        }

        return $frame . $mask . $masked;
    }
}
