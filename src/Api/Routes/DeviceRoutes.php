<?php

declare(strict_types=1);

use Hub\Api\Controllers\DeviceController;
use Hub\Api\Http\RequestContext;
use Hub\Api\Routing\ApiRoute;
use Hub\Api\Services\DeviceService;
use Psr\Http\Message\ServerRequestInterface as Request;

return static function (
    DeviceController $controller,
    DeviceService $devices,
): array {
    $auth = static fn(Request $request) => RequestContext::auth($request);

    return [
        new ApiRoute('GET', '/api/devices', static fn(array $params, Request $request): array
            => $devices->list((string)$request->getUri()->getQuery(), $auth($request), RequestContext::baseUrl($request))),
        new ApiRoute('GET', '/api/devices/{imei}', static fn(array $params, Request $request): array
            => $devices->show($params['imei'], $auth($request), RequestContext::baseUrl($request))),
        new ApiRoute('GET', '/api/devices/{imei}/stream', [$controller, 'stream']),
        new ApiRoute('GET', '/api/devices/{imei}/links', static fn(array $params, Request $request): array
            => $devices->links($params['imei'], $auth($request))),
        new ApiRoute('POST', '/api/devices/{imei}/links/{linkedImei}', static fn(array $params, Request $request): array
            => $devices->createLink($params['imei'], $params['linkedImei'], $auth($request)), status: 201),
        new ApiRoute('DELETE', '/api/devices/{imei}/links/{linkedImei}', static fn(array $params, Request $request): array
            => $devices->deleteLink($params['imei'], $params['linkedImei'], $auth($request))),
        new ApiRoute(
            'POST',
            '/api/devices/{imei}/requests',
            static fn(array $params, Request $request): array => $devices->featureRequests->requestFeature(
                $params['imei'],
                RequestContext::body($request),
                $auth($request),
                RequestContext::requestId($request),
            ),
            body: ApiRoute::JSON_BODY,
        ),
        new ApiRoute(
            'PATCH',
            '/api/devices/{imei}/association',
            static fn(array $params, Request $request): array
                => $devices->associations->associate($params['imei'], RequestContext::body($request), $auth($request)),
            body: ApiRoute::JSON_BODY,
        ),
        new ApiRoute('DELETE', '/api/devices/{imei}/association', static fn(array $params, Request $request): array
            => $devices->associations->remove($params['imei'], $auth($request))),
        new ApiRoute('GET', '/api/commands/{id}', static fn(array $params, Request $request): array
            => $devices->featureRequests->commandStatus($params['id'], $auth($request))),
        new ApiRoute(
            'POST',
            '/api/devices',
            static fn(array $params, Request $request): array => $devices->create(RequestContext::body($request), $auth($request)),
            body: ApiRoute::JSON_BODY,
            status: 201,
        ),
        new ApiRoute(
            'PUT',
            '/api/devices/{imei}',
            static fn(array $params, Request $request): array => $devices->update(
                $params['imei'],
                RequestContext::body($request),
                $auth($request),
                RequestContext::requestId($request),
            ),
            body: ApiRoute::JSON_BODY,
        ),
        new ApiRoute(
            'PATCH',
            '/api/devices/{imei}/configurations',
            static fn(array $params, Request $request): array => $devices->updateConfigurations(
                $params['imei'],
                RequestContext::body($request),
                $auth($request),
                RequestContext::requestId($request),
            ),
            body: ApiRoute::JSON_BODY,
        ),
        new ApiRoute('DELETE', '/api/devices/{imei}', static fn(array $params, Request $request): array
            => $devices->delete($params['imei'], $auth($request))),
    ];
};
