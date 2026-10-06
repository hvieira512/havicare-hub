<?php

declare(strict_types=1);

namespace Hub\Api\Http;

use Hub\Api\Auth\ApiAuthContext;
use Psr\Http\Message\ServerRequestInterface;

final class RequestContext
{
    public const ATTR_AUTH = 'apiAuth';
    public const ATTR_BODY = 'apiBody';
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
     * @return array<mixed>|null
     */
    public static function jsonBody(ServerRequestInterface $request): ?array
    {
        $decoded = json_decode(self::requestBody($request), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * O mesmo, para os pedidos em JSON ou em `multipart/form-data`, como o upload da imagem de um modelo.
     *
     * @return array<mixed>|null
     */
    public static function formOrJsonBody(ServerRequestInterface $request): ?array
    {
        $parsed = $request->getParsedBody();

        return is_array($parsed) ? $parsed : self::jsonBody($request);
    }

    /**
     * O corpo que o kernel já descodificou, nas rotas que declaram levar um.
     *
     * Chega sempre descodificado: uma rota com corpo ilegível nem sequer é chamada.
     *
     * @return array<mixed>
     */
    public static function body(ServerRequestInterface $request): array
    {
        $body = $request->getAttribute(self::ATTR_BODY);

        return is_array($body) ? $body : [];
    }

    public static function requestId(ServerRequestInterface $request): string
    {
        return (string)($request->getAttribute(self::ATTR_REQUEST_ID) ?? '');
    }

    /**
     * O endereço de quem fez o pedido: atrás do nginx, o `REMOTE_ADDR` é o do proxy. O cabeçalho
     * só vale vindo do loopback, e vale o **último** elemento da lista.
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
