<?php

use Hub\Api\Routes\Auth;
use Hub\Api\Routes\ApiUsers;
use Hub\Api\Routes\Devices;
use Hub\Api\Routes\Licenses;
use Hub\Api\Routes\Models;
use Hub\Api\Routes\Software;
use Hub\Api\Routes\Suppliers;
use Hub\Dashboard\ApiRoute;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

return static function (
    Auth $auth,
    Devices $devices,
    Models $models,
    Suppliers $suppliers,
    ApiUsers $apiUsers,
    Software $software,
    Licenses $licenses,
    callable $json,
    callable $html
): array {
    $apiAuthContext = static fn(ServerRequestInterface $request): ?\Hub\Dashboard\ApiAuthContext => $request->getAttribute('apiAuth');
    $status = static fn(array $result, int $success = 200): int => isset($result['error'])
        ? (((string)($result['error']['code'] ?? '')) === 'not_found' || str_ends_with((string)($result['error']['code'] ?? ''), '_not_found') ? 404 : 400)
        : $success;

    return [
        new ApiRoute('POST', '/api/auth/login', function (array $params, ServerRequestInterface $request) use ($auth, $json): Response {
            $result = $auth->login((string)$request->getBody());
            return $json($result, isset($result['error']) ? 401 : 200);
        }),
        new ApiRoute('GET', '/api/devices', fn(array $params, ServerRequestInterface $request): Response => $json($devices->list((string)$request->getUri()->getQuery(), $apiAuthContext($request)))),
        new ApiRoute('GET', '/api/devices/{imei}', function (array $params, ServerRequestInterface $request) use ($devices, $json, $apiAuthContext, $status): Response {
            $result = $devices->show($params['imei'], $apiAuthContext($request));
            return $json($result, $status($result));
        }),
        new ApiRoute('GET', '/api/devices/{imei}/configuration', function (array $params, ServerRequestInterface $request) use ($devices, $json, $apiAuthContext, $status): Response {
            $result = $devices->configuration($params['imei'], (string)$request->getUri()->getQuery(), $apiAuthContext($request));
            return $json($result, $status($result));
        }),
        new ApiRoute('PUT', '/api/devices/{imei}/configuration', function (array $params, ServerRequestInterface $request) use ($devices, $json, $apiAuthContext, $status): Response {
            $result = $devices->saveConfiguration($params['imei'], (string)$request->getBody(), $apiAuthContext($request));
            return $json($result, $status($result));
        }),
        new ApiRoute('POST', '/api/devices/{imei}/configuration/{key}/apply', function (array $params, ServerRequestInterface $request) use ($devices, $json, $apiAuthContext, $status): Response {
            $result = $devices->applyConfiguration($params['imei'], $params['key'], (string)$request->getBody(), $apiAuthContext($request));
            return $json($result, $status($result));
        }),
        new ApiRoute('POST', '/api/devices/{imei}/commands', function (array $params, ServerRequestInterface $request) use ($devices, $json, $apiAuthContext, $status): Response {
            $result = $devices->command($params['imei'], (string)$request->getBody(), $apiAuthContext($request));
            return $json($result, $status($result));
        }),
        new ApiRoute('GET', '/api/commands/{id}', function (array $params, ServerRequestInterface $request) use ($devices, $json, $apiAuthContext, $status): Response {
            $result = $devices->commandStatus($params['id'], $apiAuthContext($request));
            return $json($result, $status($result));
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
        new ApiRoute('GET', '/api/api-users', fn(array $params, ServerRequestInterface $request): Response => $json($apiUsers->list((string)$request->getUri()->getQuery()))),
        new ApiRoute('POST', '/api/api-users', function (array $params, ServerRequestInterface $request) use ($apiUsers, $json, $status): Response {
            $result = $apiUsers->create((string)$request->getBody());
            return $json($result, $status($result, 201));
        }),
        new ApiRoute('PUT', '/api/api-users/{id:\d+}', function (array $params, ServerRequestInterface $request) use ($apiUsers, $json, $status): Response {
            $result = $apiUsers->update((int)$params['id'], (string)$request->getBody());
            return $json($result, $status($result));
        }),
        new ApiRoute('DELETE', '/api/api-users/{id:\d+}', function (array $params) use ($apiUsers, $json, $status): Response {
            $result = $apiUsers->delete((int)$params['id']);
            return $json($result, $status($result));
        }),
        new ApiRoute('GET', '/api/software', fn(array $params, ServerRequestInterface $request): Response => $json($software->list((string)$request->getUri()->getQuery()))),
        new ApiRoute('POST', '/api/software', fn(array $params, ServerRequestInterface $request): Response => $json($software->create((string)$request->getBody()))),
        new ApiRoute('PUT', '/api/software/{id:\d+}', fn(array $params, ServerRequestInterface $request): Response => $json($software->update((int)$params['id'], (string)$request->getBody()))),
        new ApiRoute('DELETE', '/api/software/{id:\d+}', fn(array $params): Response => $json($software->delete((int)$params['id']))),
        new ApiRoute('GET', '/api/licenses', fn(array $params, ServerRequestInterface $request): Response => $json($licenses->list((string)$request->getUri()->getQuery()))),
        new ApiRoute('POST', '/api/licenses', fn(array $params, ServerRequestInterface $request): Response => $json($licenses->create((string)$request->getBody()))),
        new ApiRoute('PUT', '/api/licenses/{id:\d+}', fn(array $params, ServerRequestInterface $request): Response => $json($licenses->update((int)$params['id'], (string)$request->getBody()))),
        new ApiRoute('DELETE', '/api/licenses/{id:\d+}', fn(array $params): Response => $json($licenses->delete((int)$params['id']))),
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
