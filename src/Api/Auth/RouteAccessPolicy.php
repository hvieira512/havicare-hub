<?php

namespace Hub\Api\Auth;

final class RouteAccessPolicy
{
    /**
     * @var list<string>
     */
    private array $licenseClientAllowed = [
        // O stream do próprio inquilino. O âmbito sai do token, e por isso esta rota não tem
        // como servir outra empresa ou outra licença que não a de quem a abre.
        'GET /api/stream',
        'GET /api/devices',
        'GET /api/devices/{imei}',
        'PATCH /api/devices/{imei}/configurations',
        'GET /api/devices/{imei}/stream',
        'POST /api/devices/{imei}/requests',
        'PATCH /api/devices/{imei}/association',
        'DELETE /api/devices/{imei}/association',
        'GET /api/commands/{id}',
    ];

    public function allows(ApiAuthContext $context, string $method, string $pattern): bool
    {
        if ($context->isAdmin()) {
            return true;
        }

        return in_array($method . ' ' . $pattern, $this->licenseClientAllowed, true);
    }
}
