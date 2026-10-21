#!/usr/bin/env php
<?php

declare(strict_types=1);

$args = parseArgs($argv);
$server = (string)($args['server'] ?? 'tcp://127.0.0.1:9000');
$imei = (string)($args['imei'] ?? '');
$command = (string)($args['command'] ?? '');
$listen = isset($args['listen']);

if ($imei === '') {
    fwrite(STDERR, "Usage: php simulator/simulate.php --imei IMEI [--server URL] [--command RAW_OR_TYPE] [--listen]\n");
    exit(1);
}

$client = new TcpTextClient($server);

$client->send("IWAP00{$imei}#");
$reply = parseVivistarLine($client->receive(5));
if ($reply !== null) {
    echo '[LOGIN_REPLY] ' . json_encode($reply, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

if ($command !== '') {
    $client->send(commandLine($command));
}

if (!$listen) {
    exit(0);
}

for (;;) {
    $packet = parseVivistarLine($client->receive(30));
    if ($packet === null) {
        continue;
    }

    $type = $packet['type'];
    echo "[COMMAND] $type " . json_encode($packet, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

    if (str_starts_with($type, 'BP')) {
        $replyType = 'AP' . substr($type, 2);
        $ident = $packet['fields'][1] ?? $packet['fields'][0] ?? '123456';
        $client->send("IW{$replyType},{$ident}#");
    }
}

/**
 * @param list<string> $argv
 * @return array<string, string|true>
 */
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

function commandLine(string $command): string
{
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

/**
 * @return array{type: string, fields: list<string>, raw: string}|null
 */
function parseVivistarLine(?string $line): ?array
{
    $line = trim((string)$line);
    if (!str_starts_with($line, 'IW') || !str_ends_with($line, '#')) {
        return null;
    }

    $body = substr($line, 2, -1);
    if (preg_match('/^([A-Z]{2}[A-Z0-9]{2})(.*)$/', $body, $m) !== 1) {
        return null;
    }

    $tail = ltrim($m[2], ',');
    return [
        'type' => $m[1],
        'fields' => $tail === '' ? [] : explode(',', $tail),
        'raw' => $line,
    ];
}

class TcpTextClient
{
    /** @var resource */
    private $socket;
    private string $buffer = '';

    public function __construct(string $url)
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '127.0.0.1';
        $port = (int)($parts['port'] ?? 9000);
        $socket = @fsockopen($host, $port, $errno, $errstr, 5);
        if ($socket === false) {
            throw new RuntimeException("Failed to connect: $errstr ($errno)");
        }
        $this->socket = $socket;
    }

    public function send(string $data): void
    {
        fwrite($this->socket, $data);
    }

    public function receive(int $timeout): ?string
    {
        stream_set_timeout($this->socket, $timeout);
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
}
