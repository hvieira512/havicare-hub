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
    private bool $tlsEnabled;
    private bool $tlsVerifyPeer;
    private string $tlsCaFile;
    private string $tlsCertFile;
    private string $tlsKeyFile;
    private $socket = null;
    private int $nextPacketId = 1;
    /** @var array<string, callable> */
    private array $subscriptions = [];

    public function __construct(
        string $host,
        int $port,
        string $clientId,
        string $username = '',
        string $password = '',
        int $keepAlive = 60,
        float $timeout = 5.0,
        bool $tlsEnabled = false,
        bool $tlsVerifyPeer = true,
        string $tlsCaFile = '',
        string $tlsCertFile = '',
        string $tlsKeyFile = '',
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->clientId = $clientId;
        $this->username = $username;
        $this->password = $password;
        $this->keepAlive = max(1, $keepAlive);
        $this->timeout = max(1.0, $timeout);
        $this->tlsEnabled = $tlsEnabled;
        $this->tlsVerifyPeer = $tlsVerifyPeer;
        $this->tlsCaFile = $tlsCaFile;
        $this->tlsCertFile = $tlsCertFile;
        $this->tlsKeyFile = $tlsKeyFile;
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

    public function subscribe(string $topicFilter, callable $handler): void
    {
        $this->connectIfNeeded();
        $this->subscriptions[$topicFilter] = $handler;
        $this->sendSubscribePacket($topicFilter);
    }

    private function sendSubscribePacket(string $topicFilter): void
    {
        $packetId = $this->nextPacketId();
        $body = pack('n', $packetId) . $this->encodeString($topicFilter) . "\x00";
        $packet = "\x82" . $this->encodeRemainingLength(strlen($body)) . $body;

        $this->writeAll($packet);
        $header = $this->readExactly(1);
        if (ord($header) !== 0x90) {
            throw new \RuntimeException('Invalid SUBACK header from broker');
        }

        $remainingLength = $this->decodeRemainingLength();
        $payload = $this->readExactly($remainingLength);
        if (strlen($payload) < 3 || unpack('npacketId', substr($payload, 0, 2))['packetId'] !== $packetId) {
            throw new \RuntimeException('Invalid SUBACK payload from broker');
        }

        $returnCode = ord($payload[2]);
        if ($returnCode === 0x80) {
            throw new \RuntimeException("MQTT broker rejected subscription to {$topicFilter}");
        }
    }

    public function loopOnce(float $timeout = 0.1): void
    {
        $this->connectIfNeeded();

        $read = [$this->socket];
        $write = [];
        $except = [];
        $seconds = max(0, (int)floor($timeout));
        $microseconds = max(0, (int)(($timeout - $seconds) * 1000000));
        $ready = @stream_select($read, $write, $except, $seconds, $microseconds);
        if ($ready === false || $ready === 0) {
            return;
        }

        try {
            $header = $this->readExactly(1);
            $packetType = ord($header) & 0xF0;
            $flags = ord($header) & 0x0F;
            $remainingLength = $this->decodeRemainingLength();
            $packet = $this->readExactly($remainingLength);

            if ($packetType === 0x30) {
                $this->handlePublishPacket($packet, $flags);
            }
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

        $scheme = $this->tlsEnabled ? 'ssl' : 'tcp';
        $uri = sprintf('%s://%s:%d', $scheme, $this->host, $this->port);
        $errno = 0;
        $errstr = '';
        $context = stream_context_create($this->streamContextOptions());
        $socket = @stream_socket_client($uri, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);
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

        foreach (array_keys($this->subscriptions) as $topicFilter) {
            $this->sendSubscribePacket($topicFilter);
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

    private function nextPacketId(): int
    {
        $packetId = $this->nextPacketId++;
        if ($this->nextPacketId > 65535) {
            $this->nextPacketId = 1;
        }

        return $packetId;
    }

    private function handlePublishPacket(string $packet, int $flags): void
    {
        if (strlen($packet) < 2) {
            throw new \RuntimeException('Malformed MQTT publish packet');
        }

        $topicLength = unpack('ntopicLength', substr($packet, 0, 2))['topicLength'];
        if (strlen($packet) < 2 + $topicLength) {
            throw new \RuntimeException('Malformed MQTT publish topic');
        }

        $topic = substr($packet, 2, $topicLength);
        $offset = 2 + $topicLength;
        $qos = ($flags & 0x06) >> 1;
        if ($qos > 0) {
            if (strlen($packet) < $offset + 2) {
                throw new \RuntimeException('Malformed MQTT publish packet id');
            }
            $offset += 2;
        }

        $payload = substr($packet, $offset);
        foreach ($this->subscriptions as $handler) {
            $handler($topic, $payload);
        }
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

    private function streamContextOptions(): array
    {
        if (!$this->tlsEnabled) {
            return [];
        }

        $ssl = [
            'verify_peer' => $this->tlsVerifyPeer,
            'verify_peer_name' => $this->tlsVerifyPeer,
            'allow_self_signed' => !$this->tlsVerifyPeer,
            'peer_name' => $this->host,
        ];

        if ($this->tlsCaFile !== '') {
            $ssl['cafile'] = $this->tlsCaFile;
        }
        if ($this->tlsCertFile !== '') {
            $ssl['local_cert'] = $this->tlsCertFile;
        }
        if ($this->tlsKeyFile !== '') {
            $ssl['local_pk'] = $this->tlsKeyFile;
        }

        return ['ssl' => $ssl];
    }
}
