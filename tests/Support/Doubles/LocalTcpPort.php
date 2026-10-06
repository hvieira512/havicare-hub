<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

/**
 * Uma porta TCP livre em `127.0.0.1`, ou `null` quando o ambiente não deixa abrir sockets
 * locais e o teste deve ser ignorado. Há uma corrida teórica até o ingress se ligar à porta.
 */
final class LocalTcpPort
{
    public static function free(): ?int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!is_resource($socket)) {
            return null;
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        $parts = explode(':', (string)$name);
        return (int)array_pop($parts);
    }
}
