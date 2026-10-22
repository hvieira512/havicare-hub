<?php

declare(strict_types=1);

use Hub\Api\Http\RequestContext;
use Hub\Api\Request\ModelWriteRequest;
use Hub\Api\Routing\ApiRoute;
use Hub\Api\Services\ModelService;
use Psr\Http\Message\ServerRequestInterface;

return static function (
    ModelService $models,
): array {
    return [
        new ApiRoute('GET', '/api/models', static fn(array $params, ServerRequestInterface $request): array
            => $models->list((string)$request->getUri()->getQuery(), RequestContext::baseUrl($request))),
        new ApiRoute('GET', '/api/suppliers', static fn(array $params, ServerRequestInterface $request): array
            => $models->suppliers((string)$request->getUri()->getQuery())),
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
        // O modelo chega em JSON ou em `multipart/form-data`, porque traz a imagem com ele, e no
        // multipart tudo é texto: daí o `coerceStrings`.
        new ApiRoute(
            'POST',
            '/api/models',
            static fn(array $params, ServerRequestInterface $request, ModelWriteRequest $input): array => $models->create(
                $input,
                $request->getUploadedFiles()['image'] ?? null,
            ),
            body: ApiRoute::FORM_BODY,
            status: 201,
            request: ModelWriteRequest::class,
            coerceStrings: true,
        ),
        new ApiRoute(
            'PUT',
            '/api/models/{id:\d+}',
            static fn(array $params, ServerRequestInterface $request, ModelWriteRequest $input): array => $models->update(
                (int)$params['id'],
                $input,
                $request->getUploadedFiles()['image'] ?? null,
                RequestContext::baseUrl($request),
            ),
            body: ApiRoute::FORM_BODY,
            request: ModelWriteRequest::class,
            coerceStrings: true,
        ),
        new ApiRoute('DELETE', '/api/models/{id:\d+}', static fn(array $params): array
            => $models->delete((int)$params['id'])),
    ];
};
