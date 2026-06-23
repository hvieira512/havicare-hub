<?php

use Hub\Api\Routes\Auth;
use Hub\Api\Routes\Devices;
use Hub\Api\Routes\Models;
use Hub\Api\Routes\Suppliers;
use Hub\Dashboard\ApiRoute;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

return static function (
    Auth $auth,
    Devices $devices,
    Models $models,
    Suppliers $suppliers,
    callable $json,
    callable $html
): array {
    return [
        new ApiRoute('POST', '/api/auth/login', function (array $params, ServerRequestInterface $request) use ($auth, $json): Response {
            $result = $auth->login((string)$request->getBody());
            return $json($result, isset($result['error']) ? 401 : 200);
        }),
        new ApiRoute('GET', '/api/devices', fn(array $params, ServerRequestInterface $request): Response => $json($devices->list((string)$request->getUri()->getQuery()))),
        new ApiRoute('GET', '/api/devices/{imei}', fn(array $params): Response => $json($devices->show($params['imei']))),
        new ApiRoute('GET', '/api/devices/{imei}/configuration', fn(array $params, ServerRequestInterface $request): Response => $json($devices->configuration($params['imei'], (string)$request->getUri()->getQuery()))),
        new ApiRoute('PUT', '/api/devices/{imei}/configuration', fn(array $params, ServerRequestInterface $request): Response => $json($devices->saveConfiguration($params['imei'], (string)$request->getBody()))),
        new ApiRoute('POST', '/api/devices/{imei}/configuration/{key}/apply', fn(array $params, ServerRequestInterface $request): Response => $json($devices->applyConfiguration($params['imei'], $params['key'], (string)$request->getBody()))),
        new ApiRoute('POST', '/api/devices/{imei}/commands', fn(array $params, ServerRequestInterface $request): Response => $json($devices->command($params['imei'], (string)$request->getBody()))),
        new ApiRoute('GET', '/api/commands/{id}', function (array $params) use ($devices, $json): Response {
            $result = $devices->commandStatus($params['id']);
            return $json($result, isset($result['error']) ? 404 : 200);
        }),
        new ApiRoute('POST', '/api/devices', fn(array $params, ServerRequestInterface $request): Response => $json($devices->create((string)$request->getBody()))),
        new ApiRoute('PUT', '/api/devices/{imei}', fn(array $params, ServerRequestInterface $request): Response => $json($devices->update($params['imei'], (string)$request->getBody()))),
        new ApiRoute('DELETE', '/api/devices/{imei}', fn(array $params): Response => $json($devices->delete($params['imei']))),
        new ApiRoute('GET', '/api/models', fn(array $params, ServerRequestInterface $request): Response => $json($models->list((string)$request->getUri()->getQuery()))),
        new ApiRoute('GET', '/api/models/{id:\d+}', fn(array $params): Response => $json($models->show((int)$params['id']))),
        new ApiRoute('POST', '/api/models', fn(array $params, ServerRequestInterface $request): Response => $json($models->create($request))),
        new ApiRoute('PUT', '/api/models/{id:\d+}', fn(array $params, ServerRequestInterface $request): Response => $json($models->update((int)$params['id'], $request))),
        new ApiRoute('DELETE', '/api/models/{id:\d+}', fn(array $params): Response => $json($models->delete((int)$params['id']))),
        new ApiRoute('GET', '/api/suppliers', fn(array $params, ServerRequestInterface $request): Response => $json($suppliers->list((string)$request->getUri()->getQuery()))),
        new ApiRoute('POST', '/api/suppliers', fn(array $params, ServerRequestInterface $request): Response => $json($suppliers->create((string)$request->getBody()))),
        new ApiRoute('PUT', '/api/suppliers/{id:\d+}', fn(array $params, ServerRequestInterface $request): Response => $json($suppliers->update((int)$params['id'], (string)$request->getBody()))),
        new ApiRoute('DELETE', '/api/suppliers/{id:\d+}', fn(array $params): Response => $json($suppliers->delete((int)$params['id']))),
        new ApiRoute('GET', '/api/openapi.json', fn(array $params): Response => $json(Hub\Http\OpenApiSpec::get())),
        new ApiRoute('GET', '/api/docs', fn(array $params): Response => $html('<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>API Docs</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css">
</head>
<body>
  <div id="swagger-ui"></div>
  <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
  <script>
    SwaggerUIBundle({url: "/api/openapi.json", dom_id: "#swagger-ui"});
  </script>
</body>
</html>')),
    ];
};
