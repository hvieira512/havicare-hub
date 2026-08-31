<?php

namespace Hub\Api\Auth;

final class RouteAccessPolicy
{
    /**
     * @var list<string>
     */
    private array $licenseClientAllowed = [
        // O cliente de licença abre streams, e por isso tem de poder pedir o bilhete que os
        // abre. O bilhete herda o âmbito de quem o pediu, e portanto não amplia nada.
        'POST /api/auth/stream-ticket',
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
