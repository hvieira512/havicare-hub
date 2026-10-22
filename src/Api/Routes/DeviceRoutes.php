<?php

declare(strict_types=1);

use Hub\Api\Controllers\DeviceController;
use Hub\Api\Http\RequestContext;
use Hub\Api\Request\DeviceAssociationRequest;
use Hub\Api\Request\DeviceWriteRequest;
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
            static fn(array $params, Request $request, DeviceAssociationRequest $input): array
                => $devices->associations->associate($params['imei'], $input, $auth($request)),
            body: ApiRoute::JSON_BODY,
            request: DeviceAssociationRequest::class,
            // Os clientes mandam o `licenseId` como texto.
            coerceStrings: true,
        ),
        new ApiRoute('DELETE', '/api/devices/{imei}/association', static fn(array $params, Request $request): array
            => $devices->associations->remove($params['imei'], $auth($request))),
        new ApiRoute('GET', '/api/commands/{id}', static fn(array $params, Request $request): array
            => $devices->featureRequests->commandStatus($params['id'], $auth($request))),
        new ApiRoute(
            'POST',
            '/api/devices',
            static fn(array $params, Request $request, DeviceWriteRequest $input): array => $devices->create($input, $auth($request)),
            body: ApiRoute::JSON_BODY,
            status: 201,
            request: DeviceWriteRequest::class,
            groups: [DeviceWriteRequest::GROUP_CREATE],
            coerceStrings: true,
        ),
        new ApiRoute(
            'PUT',
            '/api/devices/{imei}',
            static fn(array $params, Request $request, DeviceWriteRequest $input): array => $devices->update(
                $params['imei'],
                $input,
                $auth($request),
                RequestContext::requestId($request),
                array_key_exists('configurations', RequestContext::body($request)),
            ),
            body: ApiRoute::JSON_BODY,
            request: DeviceWriteRequest::class,
            coerceStrings: true,
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
