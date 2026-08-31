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
        // O preflight responde aqui e não desce: era o que o `DashboardHttpServer` já fazia
        // antes sequer de olhar para o caminho, e é por isso que o `OPTIONS` nunca apareceu
        // no canal `api`. Mantém-se assim ao ficar acima do `ApiRequestLogger`.
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return $this->cors->apply(new Response(204));
        }

        $response = $next($request);

        // Uma regra só, sem excepções por caminho: antes o `/api/` e os erros JSON da
        // dashboard levavam os cabeçalhos e os recursos estáticos não. Agora levam-nos
        // também -- são públicos e servidos sem credenciais, e `*` não abre nada que um
        // pedido directo já não abrisse.
        return $response instanceof ResponseInterface ? $this->cors->apply($response) : $response;
    }
}
