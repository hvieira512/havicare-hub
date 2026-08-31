<?php

namespace Hub\Api\Http\Middleware;

use Hub\Api\Auth\ApiAuthContext;

/**
 * O que só o kernel sabe e o registo precisa: a rota que casou e a identidade que resolveu.
 *
 * O `ApiRequestLogger` corre por fora do kernel e por isso não vê os atributos que ele
 * acrescenta ao pedido -- o kernel recebe uma cópia. Este objecto viaja no atributo, e por ser
 * objecto é a mesma instância dos dois lados.
 */
final class ApiLogContext
{
    public const ATTRIBUTE = 'apiLogContext';

    public ?string $route = null;
    public string $authState = 'unknown';
    public ?ApiAuthContext $auth = null;

    public function describe(?string $route, ?ApiAuthContext $auth, string $authState): void
    {
        $this->route = $route;
        $this->auth = $auth;
        $this->authState = $authState;
    }
}
