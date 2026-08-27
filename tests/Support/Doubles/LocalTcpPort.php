<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

/**
 * Uma porta TCP livre em `127.0.0.1`, para os testes que levantam um ingress a sério.
 *
 * Devolve `null` quando o ambiente não deixa abrir sockets locais -- é o caso em sandboxes,
 * e quem chama marca o teste como ignorado em vez de falhar por uma razão que não é a dele.
 * A porta é escolhida pelo sistema e libertada logo: há uma corrida teórica entre isto e o
 * ingress ligar-se, que na prática nunca se viu num único processo de testes.
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
