<?php

namespace Hub\Device;

use Hub\Log\Logger;
use Hub\Tcp\TcpDeviceConnection;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface as ReactConnection;
use React\Socket\SocketServer;

class HubTcpIngress
{
    private DeviceHubServer $hubServer;
    private SocketServer $socket;
    private array $buffers = [];
    private int $nextResourceId = 1000000;

    public function __construct(DeviceHubServer $hubServer, LoopInterface $loop, string $host, int $port)
    {
        $this->hubServer = $hubServer;
        $this->socket = new SocketServer("$host:$port", [], $loop);
        $this->socket->on('connection', function (ReactConnection $connection): void {
            $this->onConnection($connection);
        });

        Logger::channel('hub')->info("TCP hub ingress at tcp://$host:$port");
    }

    private function onConnection(ReactConnection $connection): void
    {
        $resourceId = $this->nextResourceId++;
        $client = new TcpDeviceConnection($connection, $resourceId);
        $this->buffers[$resourceId] = '';
        $this->hubServer->onOpen($client);

        $connection->on('data', function ($data) use ($client, $resourceId): void {
            $this->onData($client, $resourceId, (string)$data);
        });
        $connection->on('close', function () use ($client, $resourceId): void {
            unset($this->buffers[$resourceId]);
            $this->hubServer->onClose($client);
        });
        $connection->on('error', function (\Throwable $error) use ($client): void {
            $this->hubServer->onError($client, $error instanceof \Exception ? $error : new \RuntimeException($error->getMessage(), 0, $error));
        });
    }

    private function onData(TcpDeviceConnection $client, int $resourceId, string $data): void
    {
        $this->buffers[$resourceId] = ($this->buffers[$resourceId] ?? '') . $data;

        while (isset($this->buffers[$resourceId]) && ($packetLength = $this->nextPacketLength($this->buffers[$resourceId])) !== null) {
            $packet = substr($this->buffers[$resourceId], 0, $packetLength);
            $this->buffers[$resourceId] = substr($this->buffers[$resourceId], $packetLength);
            if ($packet !== '' && trim($packet) !== '') {
                $binaryFrame = $this->isWonlexFrame($packet) || $this->isPillFrame($packet);
                $this->hubServer->onMessage($client, $binaryFrame ? $packet : trim($packet));
                if (!isset($this->buffers[$resourceId])) {
                    return;
                }
            }
        }

        if (isset($this->buffers[$resourceId]) && strlen($this->buffers[$resourceId]) > 65535) {
            Logger::channel('hub')->warning("TCP buffer overflow for connection=$resourceId; resetting buffer");
            $this->buffers[$resourceId] = '';
        }
    }

    private function nextPacketLength(string $buffer): ?int
    {
        if ($this->isWonlexFrameStart($buffer)) {
            if (strlen($buffer) < 4) {
                return null;
            }

            $header = unpack('nstart/nlength', substr($buffer, 0, 4));
            $length = (int)($header['length'] ?? 0);
            $packetLength = 4 + $length;

            return strlen($buffer) >= $packetLength ? $packetLength : null;
        }

        if ($this->isPillFrameStart($buffer)) {
            if (strlen($buffer) < 3) {
                return null;
            }

            // Length conta de Status ao fim dos dados; a trama é 0xAA + Length(2) + dados + CRC(2).
            // Ordem do anfitrião: little-endian ('v'), ao contrário do 'n' do Wonlex.
            $length = (int)(unpack('vlength', substr($buffer, 1, 2))['length'] ?? 0);
            $packetLength = 3 + $length + 2;

            return strlen($buffer) >= $packetLength ? $packetLength : null;
        }

        $hashPos = strpos($buffer, '#');
        $bracketPos = strpos($buffer, ']');

        if ($hashPos === false) {
            return $bracketPos === false ? null : $bracketPos + 1;
        }
        if ($bracketPos === false) {
            return $hashPos + 1;
        }

        return min($hashPos, $bracketPos) + 1;
    }

    private function isWonlexFrame(string $packet): bool
    {
        return $this->isWonlexFrameStart($packet)
            && strlen($packet) >= 4
            && strlen($packet) === 4 + (int)(unpack('nlength', substr($packet, 2, 2))['length'] ?? -1);
    }

    private function isWonlexFrameStart(string $buffer): bool
    {
        return strlen($buffer) >= 2 && substr($buffer, 0, 2) === "\xFC\xAF";
    }

    private function isPillFrame(string $packet): bool
    {
        return $this->isPillFrameStart($packet)
            && strlen($packet) >= 3
            && strlen($packet) === 3 + (int)(unpack('vlength', substr($packet, 1, 2))['length'] ?? -1) + 2;
    }

    private function isPillFrameStart(string $buffer): bool
    {
        return strlen($buffer) >= 1 && $buffer[0] === "\xAA";
    }
}
