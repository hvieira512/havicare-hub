<?php

/**
 * Pendem da licença porque é dela que são: o acesso à cloud do fabricante dos radares não é
 * comum à frota, e nem o endereço base coincide entre inquilinos.
 */

use Hub\Api\Http\RequestContext;
use Hub\Api\Routing\ApiRoute;
use Hub\Api\Services\RadarCredentialsService;
use Psr\Http\Message\ServerRequestInterface;
use React\Promise\PromiseInterface;

return static function (
    RadarCredentialsService $radarCredentials,
): array {
    return [
        new ApiRoute('GET', '/api/licenses/{id:\d+}/radar-credentials', static fn(array $params): array
            => $radarCredentials->show((int)$params['id'])),
        new ApiRoute(
            'PUT',
            '/api/licenses/{id:\d+}/radar-credentials',
            static fn(array $params, ServerRequestInterface $request): array
                => $radarCredentials->save((int)$params['id'], RequestContext::body($request)),
            body: ApiRoute::JSON_BODY,
        ),
        new ApiRoute('DELETE', '/api/licenses/{id:\d+}/radar-credentials', static fn(array $params): array
            => $radarCredentials->delete((int)$params['id'])),
        // Experimentar não grava: o que vai no corpo é o que está no ecrã, e os segredos em
        // branco caem nos que já lá estão.
        new ApiRoute(
            'POST',
            '/api/licenses/{id:\d+}/radar-credentials/check',
            static fn(array $params, ServerRequestInterface $request): PromiseInterface
                => $radarCredentials->check((int)$params['id'], RequestContext::body($request)),
            body: ApiRoute::JSON_BODY,
        ),
    ];
};
