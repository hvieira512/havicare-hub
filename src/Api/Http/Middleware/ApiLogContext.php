<?php

declare(strict_types=1);

namespace Hub\Api\Http\Middleware;

use Hub\Api\Auth\ApiAuthContext;

/**
 * O que só o kernel sabe e o registo precisa. O `ApiRequestLogger` corre por fora do kernel, e
 * este objecto viaja no atributo do pedido como a mesma instância dos dois lados.
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
