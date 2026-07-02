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
