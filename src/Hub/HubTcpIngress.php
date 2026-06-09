<?php

namespace App\Hub;

use App\Log\Logger;
use App\Tcp\TcpDeviceConnection;
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

        while (($pos = strpos($this->buffers[$resourceId], '#')) !== false) {
            $packet = substr($this->buffers[$resourceId], 0, $pos + 1);
            $this->buffers[$resourceId] = substr($this->buffers[$resourceId], $pos + 1);
            if (trim($packet) !== '') {
                $this->hubServer->onMessage($client, trim($packet));
                if (!isset($this->buffers[$resourceId])) {
                    return;
                }
            }
        }

        if (strlen($this->buffers[$resourceId]) > 65535) {
            Logger::channel('hub')->warning("TCP buffer overflow for connection=$resourceId; resetting buffer");
            $this->buffers[$resourceId] = '';
        }
    }
}
