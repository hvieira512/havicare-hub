<?php

namespace Hub\Api\Http;

use Hub\Api\Auth\ApiAuthContext;
use Psr\Http\Message\ServerRequestInterface;

final class RequestContext
{
    public const ATTR_AUTH = 'apiAuth';
    public const ATTR_RAW_BODY = 'apiRawBody';
    public const ATTR_REQUEST_ID = 'apiRequestId';
    public const ATTR_ROUTE_PATTERN = 'apiRoutePattern';

    /** O proxy corre na mesma máquina; nenhum outro endereço pode declarar por quem fala. */
    private const TRUSTED_PROXIES = ['127.0.0.1', '::1'];

    public static function auth(ServerRequestInterface $request): ?ApiAuthContext
    {
        $auth = $request->getAttribute(self::ATTR_AUTH);
        return $auth instanceof ApiAuthContext ? $auth : null;
    }

    public static function requestBody(ServerRequestInterface $request): string
    {
        return (string)($request->getAttribute(self::ATTR_RAW_BODY) ?? (string)$request->getBody());
    }

    /**
     * O corpo já descodificado, ou `null` quando não é um objecto JSON.
     *
     * Descodificar é trabalho do transporte: assim os serviços recebem o array e não têm de
     * saber que do outro lado da chamada havia texto.
     *
     * @return array<mixed>|null
     */
    public static function jsonBody(ServerRequestInterface $request): ?array
    {
        $decoded = json_decode(self::requestBody($request), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * O mesmo, para os pedidos que tanto chegam em JSON como em `multipart/form-data`.
     *
     * O upload da imagem de um modelo obriga ao formulário, e o corpo de um `multipart` não
     * é JSON nenhum -- vem já partido pelo servidor.
     *
     * @return array<mixed>|null
     */
    public static function formOrJsonBody(ServerRequestInterface $request): ?array
    {
        $parsed = $request->getParsedBody();

        return is_array($parsed) ? $parsed : self::jsonBody($request);
    }

    public static function requestId(ServerRequestInterface $request): string
    {
        return (string)($request->getAttribute(self::ATTR_REQUEST_ID) ?? '');
    }

    /**
     * O endereço de quem fez o pedido, e não o de quem o entregou.
     *
     * Com o nginx à frente, o `REMOTE_ADDR` é sempre o do proxy, e o `LoginThrottle` conta
     * tentativas por endereço: sem isto, todos os utilizadores caem no mesmo balde e a
     * vigésima primeira tentativa de qualquer um tranca os restantes.
     *
     * O cabeçalho só vale vindo do loopback, que é onde o nosso proxy está. E vale o
     * **último** elemento da lista: o `$proxy_add_x_forwarded_for` do nginx acrescenta o
     * endereço da ligação ao que o cliente tiver mandado, portanto tudo o que vem antes é
     * escolha de quem ligou.
     */
    public static function clientAddress(ServerRequestInterface $request): string
    {
        $remote = trim((string)($request->getServerParams()['REMOTE_ADDR'] ?? ''));
        if (!in_array($remote, self::TRUSTED_PROXIES, true)) {
            return $remote;
        }

        $forwarded = array_filter(array_map(
            'trim',
            explode(',', $request->getHeaderLine('X-Forwarded-For'))
        ), static fn (string $entry): bool => $entry !== '');

        return $forwarded === [] ? $remote : (string)end($forwarded);
    }

    public static function baseUrl(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $host = $uri->getHost();
        if ($host === '') {
            return '';
        }

        $scheme = $uri->getScheme() ?: 'http';
        $port = $uri->getPort();
        $authority = $host;
        if ($port !== null) {
            $defaultPort = ($scheme === 'https') ? 443 : 80;
            if ($port !== $defaultPort) {
                $authority .= ':' . $port;
            }
        }

        return $scheme . '://' . $authority;
    }
}
