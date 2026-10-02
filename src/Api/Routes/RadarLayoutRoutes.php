<?php

/**
 * A planta de um radar. O `sync` é o botão e mais nada: a ida à cloud do fabricante acontece
 * quando alguém a pede, e nunca por conta própria.
 */

declare(strict_types=1);

use Hub\Api\Routing\ApiRoute;
use Hub\Api\Services\RadarLayoutService;
use Hub\Api\Http\RequestContext;
use Psr\Http\Message\ServerRequestInterface;
use React\Promise\PromiseInterface;

return static function (
    RadarLayoutService $radarLayouts,
): array {
    return [
        new ApiRoute(
            'GET',
            '/api/devices/{imei}/radar-layout',
            static fn(array $params, ServerRequestInterface $request): array
                => $radarLayouts->show($params['imei'], RequestContext::auth($request)),
        ),
        // A promessa segue para o kernel em vez de se esperar por ela: o processo tem um
        // event loop só, e a ingestão pararia enquanto isto bloqueasse.
        new ApiRoute(
            'POST',
            '/api/devices/{imei}/radar-layout/sync',
            static fn(array $params, ServerRequestInterface $request): PromiseInterface
                => $radarLayouts->sync($params['imei'], RequestContext::auth($request)),
        ),
    ];
};
