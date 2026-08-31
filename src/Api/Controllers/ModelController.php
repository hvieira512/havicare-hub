<?php

namespace Hub\Api\Controllers;

use Hub\Api\Http\ApiError;
use Hub\Api\Http\ErrorStatusMapper;
use Hub\Api\Http\JsonResponder;
use Hub\Api\Http\RequestContext;
use Hub\Api\Services\ModelService;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

final class ModelController
{
    public function __construct(
        private ModelService $service,
        private JsonResponder $json,
        private ErrorStatusMapper $status,
    ) {
    }

    public function list(ServerRequestInterface $request): Response
    {
        return $this->json->respond($this->service->list((string)$request->getUri()->getQuery(), RequestContext::baseUrl($request)));
    }

    public function filters(ServerRequestInterface $request): Response
    {
        return $this->json->respond($this->service->filters());
    }

    public function deviceTypeSuppliersModels(ServerRequestInterface $request): Response
    {
        return $this->json->respond($this->service->deviceTypeSuppliersModels(RequestContext::baseUrl($request)));
    }

    public function template(ServerRequestInterface $request): Response
    {
        $result = $this->service->template((string)$request->getUri()->getQuery());

        return $this->json->respond($result, $this->status->map($result));
    }

    public function show(array $params, ServerRequestInterface $request): Response
    {
        return $this->json->respond($this->service->show((int)$params['id'], RequestContext::baseUrl($request)));
    }

    /** O modelo chega em JSON ou em `multipart/form-data`, porque traz a imagem com ele. */
    public function create(ServerRequestInterface $request): Response
    {
        $payload = RequestContext::formOrJsonBody($request);
        if ($payload === null) {
            return $this->json->respond(ApiError::invalidJson()->toArray());
        }

        return $this->json->respond($this->service->create($payload, $request->getUploadedFiles()['image'] ?? null));
    }

    public function update(array $params, ServerRequestInterface $request): Response
    {
        $payload = RequestContext::formOrJsonBody($request);
        if ($payload === null) {
            return $this->json->respond(ApiError::invalidJson()->toArray());
        }

        return $this->json->respond(
            $this->service->update((int)$params['id'], $payload, $request->getUploadedFiles()['image'] ?? null)
        );
    }

    public function delete(array $params): Response
    {
        return $this->json->respond($this->service->delete((int)$params['id']));
    }
}
