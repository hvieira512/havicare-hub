<?php

namespace Hub\Api\Auth;

use Psr\Http\Message\ServerRequestInterface;

final class BearerTokenResolver
{
    public function __construct(private ApiTokenStore $tokens)
    {
    }

    public function resolve(ServerRequestInterface $request): ?ApiAuthContext
    {
        $header = $request->getHeaderLine('Authorization');
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return $this->tokens->context((string)$matches[1]);
        }

        parse_str((string)$request->getUri()->getQuery(), $params);
        $queryToken = trim((string)($params['access_token'] ?? ''));
        if ($queryToken === '') {
            return null;
        }

        return $this->tokens->context($queryToken);
    }
}
