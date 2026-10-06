<?php

declare(strict_types=1);

use Hub\Api\Http\RequestContext;
use Hub\Api\Routing\ApiRoute;
use Hub\Api\Services\ModelService;
use Psr\Http\Message\ServerRequestInterface;

return static function (
    ModelService $models,
): array {
    return [
        new ApiRoute('GET', '/api/models', static fn(array $params, ServerRequestInterface $request): array
            => $models->list((string)$request->getUri()->getQuery(), RequestContext::baseUrl($request))),
        new ApiRoute('GET', '/api/device-types/suppliers', static fn(): array => $models->filters()),
        new ApiRoute(
            'GET',
            '/api/device-types/suppliers/models',
            static fn(array $params, ServerRequestInterface $request): array
                => $models->deviceTypeSuppliersModels(RequestContext::baseUrl($request)),
        ),
        new ApiRoute('GET', '/api/models/template', static fn(array $params, ServerRequestInterface $request): array
            => $models->template((string)$request->getUri()->getQuery())),
        new ApiRoute('GET', '/api/models/{id:\d+}', static fn(array $params, ServerRequestInterface $request): array
            => $models->show((int)$params['id'], RequestContext::baseUrl($request))),
        // O modelo chega em JSON ou em `multipart/form-data`, porque traz a imagem com ele.
        new ApiRoute(
            'POST',
            '/api/models',
            static fn(array $params, ServerRequestInterface $request): array => $models->create(
                RequestContext::body($request),
                $request->getUploadedFiles()['image'] ?? null,
            ),
            body: ApiRoute::FORM_BODY,
            status: 201,
        ),
        new ApiRoute(
            'PUT',
            '/api/models/{id:\d+}',
            static fn(array $params, ServerRequestInterface $request): array => $models->update(
                (int)$params['id'],
                RequestContext::body($request),
                $request->getUploadedFiles()['image'] ?? null,
            ),
            body: ApiRoute::FORM_BODY,
        ),
        new ApiRoute('DELETE', '/api/models/{id:\d+}', static fn(array $params): array
            => $models->delete((int)$params['id'])),
    ];
};
