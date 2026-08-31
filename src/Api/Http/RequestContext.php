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
