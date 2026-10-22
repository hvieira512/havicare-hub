<?php

declare(strict_types=1);

namespace Tests\Integration\Api;

use GuzzleHttp\Psr7\ServerRequest;
use Hub\Api\Auth\ApiTokenStore;
use Hub\Api\Request\DeviceWriteRequest;
use Hub\Api\Services\AuthService;
use Hub\Api\Services\DenylistService;
use Hub\Api\Services\ModelService;
use Tests\Support\DashboardHttpTestCase;
use Tests\Support\DevicesApiFixture;
use Tests\Support\Doubles\InMemoryRedisClient;

/** Um corpo recusado diz que campo está mal, em `error.fields`, com um `code` estável. */
final class ValidationIsUniformTest extends DashboardHttpTestCase
{
    use DevicesApiFixture;

    public function testARefusedBodySaysWhichFieldIsWrong(): void
    {
        [$devices, $db] = $this->makeApi();
        $auth = new AuthService(new ApiTokenStore(new InMemoryRedisClient()), $db);

        $refusals = [
            'denylist unblock' => (new DenylistService($db))->unblock(' '),
            'model template' => (new ModelService($db))->template(''),
            'device update' => $devices->update('861265061009822', new DeviceWriteRequest(), carriesConfigurations: true),
            'device configurations' => $devices->updateConfigurations('861265061009822', []),
            'device request' => $devices->featureRequests->requestFeature('861265061009822', []),
            'login' => $auth->login(['username' => 'admin']),
            'license token' => $auth->licenseToken([]),
        ];

        // As rotas que declaram a classe do pedido recusam no kernel, antes do serviço.
        $server = $this->makeServer();
        $token = $this->loginToken($server, 'admin', 'secret');
        foreach (
            [
                'POST /api/denylist',
                'PATCH /api/notifications/read',
                'POST /api/companies',
                'POST /api/licenses',
                'POST /api/users',
                'POST /api/models',
                'POST /api/devices',
                'PATCH /api/devices/861265061009822/association',
            ] as $route
        ) {
            [$method, $path] = explode(' ', $route);
            $response = $server(new ServerRequest(
                $method,
                $path,
                ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
                '{}',
            ));
            $refusals[$route] = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        }

        $without = [];
        foreach ($refusals as $name => $result) {
            if (!is_string($result['error']['code'] ?? null)) {
                $without[] = "{$name}: no code";
                continue;
            }
            if (!is_array($result['error']['fields'] ?? null) || $result['error']['fields'] === []) {
                $without[] = "{$name}: no fields";
            }
        }

        self::assertSame([], $without);
    }
}
