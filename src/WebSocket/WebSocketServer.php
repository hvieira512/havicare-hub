<?php

namespace Hub\WebSocket;

use GuzzleHttp\Psr7\Message;
use Ratchet\RFC6455\Handshake\ServerNegotiator;
use Ratchet\RFC6455\Handshake\RequestVerifier;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface as ReactConnection;
use React\Socket\SocketServer;

class WebSocketServer
{
    private SocketServer $socket;
    private MessageComponentInterface $handler;
    private int $nextId = 1;

    public function __construct(
        MessageComponentInterface $handler,
        string $uri,
        array $context = [],
        ?LoopInterface $loop = null,
    ) {
        $this->handler = $handler;
        $this->socket = new SocketServer($uri, $context, $loop);

        $this->socket->on('connection', function (ReactConnection $conn): void {
            $this->handleConnection($conn);
        });
    }

    private function handleConnection(ReactConnection $conn): void
    {
        $id = $this->nextId++;
        $negotiator = new ServerNegotiator(new RequestVerifier());
        $httpBuffer = '';
        $webSocketConnection = null;
        $msgBuffer = null;

        $conn->on('data', function (string $data) use (
            $conn, &$httpBuffer, $negotiator, $id,
            &$webSocketConnection, &$msgBuffer,
        ): void {
            if ($webSocketConnection !== null) {
                $msgBuffer?->onData($data);
                return;
            }

            $httpBuffer .= $data;

            $headerEnd = strpos($httpBuffer, "\r\n\r\n");
            if ($headerEnd === false) {
                return;
            }

            $headerPart = substr($httpBuffer, 0, $headerEnd + 4);
            $httpBuffer = '';

            try {
                $request = Message::parseRequest($headerPart);
            } catch (\Throwable $e) {
                $conn->end();
                return;
            }

            $response = $negotiator->handshake($request);

            if ($response->getStatusCode() !== 101) {
                $conn->end();
                return;
            }

            $acceptKey = $response->getHeaderLine('Sec-WebSocket-Accept');
            $conn->write(
                "HTTP/1.1 101 Switching Protocols\r\n"
                . "Upgrade: websocket\r\n"
                . "Connection: Upgrade\r\n"
                . "Sec-WebSocket-Accept: {$acceptKey}\r\n"
                . "\r\n"
            );

            $connection = new WebSocketConnection($conn, $id);

            $msgBuffer = new MessageBuffer(
                new CloseFrameChecker(),
                function ($message, $buffer) use ($connection): void {
                    $this->handler->onMessage($connection, (string)$message);
                },
                function (Frame $frame, $buffer) use ($conn): void {
                    if ($frame->getOpcode() === Frame::OP_CLOSE) {
                        $conn->end();
                    } elseif ($frame->getOpcode() === Frame::OP_PING) {
                        $pong = new Frame(null, true, Frame::OP_PONG);
                        $conn->write($pong->getContents());
                    }
                },
                true,
                null,
                null,
                null,
                function (string $data) use ($conn): void {
                    $conn->write($data);
                },
            );

            $connection->setMessageBuffer($msgBuffer);
            $webSocketConnection = $connection;
            $this->handler->onOpen($connection);

            $remaining = substr($data, $headerEnd + 4);
            if ($remaining !== '') {
                $msgBuffer->onData($remaining);
            }
        });

        $conn->on('close', function () use (&$webSocketConnection): void {
            if ($webSocketConnection !== null) {
                $this->handler->onClose($webSocketConnection);
            }
        });

        $conn->on('error', function (\Exception $e) use (&$webSocketConnection, $conn): void {
            if ($webSocketConnection !== null) {
                $this->handler->onError($webSocketConnection, $e);
            }
            $conn->close();
        });
    }
}
