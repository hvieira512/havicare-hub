<?php

namespace App\Mqtt;

class SimpleClient
{
    private string $host;
    private int $port;
    private string $clientId;
    private string $username;
    private string $password;
    private int $keepAlive;
    private float $timeout;
    private $socket = null;

    public function __construct(
        string $host,
        int $port,
        string $clientId,
        string $username = '',
        string $password = '',
        int $keepAlive = 60,
        float $timeout = 5.0,
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->clientId = $clientId;
        $this->username = $username;
        $this->password = $password;
        $this->keepAlive = max(1, $keepAlive);
        $this->timeout = max(1.0, $timeout);
    }

    public function publish(string $topic, string $payload, bool $retain = false): void
    {
        $this->connectIfNeeded();

        $header = 0x30 | ($retain ? 0x01 : 0x00);
        $variableHeader = $this->encodeString($topic);
        $body = $variableHeader . $payload;
        $packet = chr($header) . $this->encodeRemainingLength(strlen($body)) . $body;

        try {
            $this->writeAll($packet);
        } catch (\Throwable $e) {
            $this->disconnect();
            throw $e;
        }
    }

    public function disconnect(): void
    {
        if (!is_resource($this->socket)) {
            $this->socket = null;
            return;
        }

        @fwrite($this->socket, "\xE0\x00");
        @fclose($this->socket);
        $this->socket = null;
    }

    private function connectIfNeeded(): void
    {
        if (is_resource($this->socket)) {
            return;
        }

        $uri = sprintf('tcp://%s:%d', $this->host, $this->port);
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($uri, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT);
        if (!is_resource($socket)) {
            throw new \RuntimeException("MQTT connect failed to {$uri}: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, (int)$this->timeout);
        $this->socket = $socket;

        $flags = 0x02; // Clean Session
        $payload = $this->encodeString($this->clientId);

        if ($this->username !== '') {
            $flags |= 0x80;
            $payload .= $this->encodeString($this->username);
        }
        if ($this->password !== '') {
            $flags |= 0x40;
            $payload .= $this->encodeString($this->password);
        }

        $variableHeader =
            $this->encodeString('MQTT') .
            chr(0x04) .
            chr($flags) .
            pack('n', $this->keepAlive);

        $connectBody = $variableHeader . $payload;
        $packet = "\x10" . $this->encodeRemainingLength(strlen($connectBody)) . $connectBody;

        $this->writeAll($packet);

        $connAckHeader = $this->readExactly(1);
        if (ord($connAckHeader) !== 0x20) {
            $this->disconnect();
            throw new \RuntimeException('Invalid CONNACK header from broker');
        }

        $remainingLength = $this->decodeRemainingLength();
        $connAckPayload = $this->readExactly($remainingLength);
        if (strlen($connAckPayload) !== 2) {
            $this->disconnect();
            throw new \RuntimeException('Invalid CONNACK payload length');
        }

        $returnCode = ord($connAckPayload[1]);
        if ($returnCode !== 0x00) {
            $this->disconnect();
            throw new \RuntimeException("MQTT broker refused connection (code {$returnCode})");
        }
    }

    private function writeAll(string $bytes): void
    {
        $offset = 0;
        $total = strlen($bytes);

        while ($offset < $total) {
            $written = @fwrite($this->socket, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('MQTT socket write failed');
            }
            $offset += $written;
        }
    }

    private function readExactly(int $len): string
    {
        $buffer = '';
        while (strlen($buffer) < $len) {
            $chunk = @fread($this->socket, $len - strlen($buffer));
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('MQTT socket read failed');
            }
            $buffer .= $chunk;
        }
        return $buffer;
    }

    private function decodeRemainingLength(): int
    {
        $multiplier = 1;
        $value = 0;
        $loops = 0;

        do {
            $encoded = ord($this->readExactly(1));
            $value += ($encoded & 0x7F) * $multiplier;
            $multiplier *= 128;
            $loops++;
            if ($loops > 4) {
                throw new \RuntimeException('Malformed MQTT remaining length');
            }
        } while (($encoded & 0x80) !== 0);

        return $value;
    }

    private function encodeRemainingLength(int $length): string
    {
        $encoded = '';
        do {
            $digit = $length % 128;
            $length = intdiv($length, 128);
            if ($length > 0) {
                $digit |= 0x80;
            }
            $encoded .= chr($digit);
        } while ($length > 0);

        return $encoded;
    }

    private function encodeString(string $value): string
    {
        return pack('n', strlen($value)) . $value;
    }
}
