<?php

use Hub\Api\Routes\Auth;
use Hub\Api\Routes\ApiUsers;
use Hub\Api\Routes\Company;
use Hub\Api\Routes\Devices;
use Hub\Api\Routes\Licenses;
use Hub\Api\Routes\Models;
use Hub\Api\Routes\Suppliers;
use Hub\Dashboard\ApiRoute;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\Message\Response;
use React\Stream\ThroughStream;

return static function (
    Auth $auth,
    Devices $devices,
    Models $models,
    Suppliers $suppliers,
    ApiUsers $apiUsers,
    Company $company,
    Licenses $licenses,
    callable $json,
    callable $html
): array {
    $apiAuthContext = static fn(ServerRequestInterface $request): ?\Hub\Dashboard\ApiAuthContext => $request->getAttribute('apiAuth');
    $status = static function (array $result, int $success = 200): int {
        if (!isset($result['error'])) {
            return $success;
        }

        $code = (string)($result['error']['code'] ?? '');
        if ($code === 'forbidden') {
            return 403;
        }
        if ($code === 'not_found' || str_ends_with($code, '_not_found')) {
            return 404;
        }

        return 400;
    };

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
        new ApiRoute('GET', '/api/devices/{imei}/recent', function (array $params, ServerRequestInterface $request) use ($devices, $json, $apiAuthContext, $status): Response {
            $result = $devices->recent($params['imei'], $apiAuthContext($request));
            return $json($result, $status($result));
        }),
        new ApiRoute('GET', '/api/devices/{imei}/actions', function (array $params, ServerRequestInterface $request) use ($devices, $json, $apiAuthContext, $status): Response {
            $result = $devices->availableActions($params['imei'], $apiAuthContext($request));
            return $json($result, $status($result));
        }),
        new ApiRoute('GET', '/api/devices/{imei}/stream', function (array $params, ServerRequestInterface $request) use ($devices, $json, $apiAuthContext): Response {
            $imei = $params['imei'];
            $auth = $apiAuthContext($request);

            $snapshot = $devices->recent($imei, $auth);
            if (isset($snapshot['error'])) {
                return $json($snapshot, 404);
            }

            $loop = Loop::get();
            $stream = new ThroughStream();

            $snapshot['actions'] = $devices->availableActions($imei, $auth);
            $initialPayload = "event: snapshot\ndata: " . json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
            $loop->futureTick(static function () use ($stream, $initialPayload): void {
                if ($stream->isWritable()) {
                    $stream->write($initialPayload);
                }
            });

            $timer = $loop->addPeriodicTimer(2, function () use ($imei, $devices, $auth, $stream): void {
                if (!$stream->isWritable()) {
                    return;
                }
                $data = $devices->recent($imei, $auth);
                if (!isset($data['error'])) {
                    $stream->write("event: update\ndata: " . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n");
                }
            });

            $stream->on('close', static function () use ($timer, $loop): void {
                $loop->cancelTimer($timer);
            });

            return new Response(200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ], $stream);
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
        new ApiRoute('PATCH', '/api/devices/{imei}/association', function (array $params, ServerRequestInterface $request) use ($devices, $json, $apiAuthContext, $status): Response {
            $result = $devices->patchAssociation($params['imei'], (string)$request->getBody(), $apiAuthContext($request));
            return $json($result, $status($result));
        }),
        new ApiRoute('DELETE', '/api/devices/{imei}/association', function (array $params, ServerRequestInterface $request) use ($devices, $json, $apiAuthContext, $status): Response {
            $result = $devices->deleteAssociation($params['imei'], $apiAuthContext($request));
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
        new ApiRoute('GET', '/api/companies', fn(array $params, ServerRequestInterface $request): Response => $json($company->list((string)$request->getUri()->getQuery()))),
        new ApiRoute('POST', '/api/companies', fn(array $params, ServerRequestInterface $request): Response => $json($company->create((string)$request->getBody()))),
        new ApiRoute('PUT', '/api/companies/{id:\d+}', fn(array $params, ServerRequestInterface $request): Response => $json($company->update((int)$params['id'], (string)$request->getBody()))),
        new ApiRoute('DELETE', '/api/companies/{id:\d+}', fn(array $params): Response => $json($company->delete((int)$params['id']))),
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
