<?php

declare(strict_types=1);

namespace Hub\Api\Http\Middleware;

use Hub\Api\Http\CorsPolicy;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

final class CorsMiddleware
{
    public function __construct(private CorsPolicy $cors)
    {
    }

    public function __invoke(ServerRequestInterface $request, callable $next): mixed
    {
        // O preflight responde aqui e não desce. Acima do `ApiRequestLogger`, e por isso o
        // `OPTIONS` não aparece no canal `api`.
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return $this->cors->apply(new Response(204), $request);
        }

        $response = $next($request);

        // Uma regra só, também nos estáticos: são públicos e servidos sem credenciais.
        return $response instanceof ResponseInterface ? $this->cors->apply($response, $request) : $response;
    }
}
