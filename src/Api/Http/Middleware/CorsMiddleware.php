<?php

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

        // Uma regra só, sem excepções por caminho: antes o `/api/` e os erros JSON da
        // dashboard levavam os cabeçalhos e os recursos estáticos não. Agora levam-nos
        // também -- são públicos e servidos sem credenciais, e a política aberta não abre
        // nada que um pedido directo já não abrisse.
        return $response instanceof ResponseInterface ? $this->cors->apply($response, $request) : $response;
    }
}
