<?php

declare(strict_types=1);

namespace Hub\Api\Http\Middleware;

use Hub\Api\Http\CorsPolicy;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;

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

        // Uma regra só, também nos estáticos: são públicos e servidos sem credenciais.
        $apply = fn(mixed $response): mixed => $response instanceof ResponseInterface
            ? $this->cors->apply($response, $request)
            : $response;
        $response = $next($request);

        return $response instanceof PromiseInterface ? $response->then($apply) : $apply($response);
    }
}
