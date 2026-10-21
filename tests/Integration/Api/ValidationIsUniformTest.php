<?php

declare(strict_types=1);

namespace Tests\Integration\Api;

use Hub\Api\Auth\ApiTokenStore;
use Hub\Api\Services\ApiUserService;
use Hub\Api\Services\AuthService;
use Hub\Api\Services\CompanyService;
use Hub\Api\Services\DashboardNotificationService;
use Hub\Api\Services\DenylistService;
use Hub\Api\Services\LicenseService;
use Hub\Api\Services\ModelService;
use Tests\Support\DevicesApiFixture;
use Tests\Support\Doubles\InMemoryRedisClient;
use Tests\Support\MysqlDashboardTestCase;

/** Um corpo recusado diz que campo está mal, em `error.fields`, com um `code` estável. */
final class ValidationIsUniformTest extends MysqlDashboardTestCase
{
    use DevicesApiFixture;

    public function testARefusedBodySaysWhichFieldIsWrong(): void
    {
        [$devices, $db] = $this->makeApi();
        $auth = new AuthService(new ApiTokenStore(new InMemoryRedisClient()), $db);

        $refusals = [
            'denylist' => (new DenylistService($db))->block([], 'admin'),
            'denylist unblock' => (new DenylistService($db))->unblock(' '),
            'notifications' => (new DashboardNotificationService($db))->markRead([]),
            'companies' => (new CompanyService($db))->create([]),
            'licenses' => (new LicenseService($db))->create([]),
            'users' => (new ApiUserService($db))->create([]),
            'models' => (new ModelService($db))->create([]),
            'model template' => (new ModelService($db))->template(''),
            'device create' => $devices->create([]),
            'device update' => $devices->update('861265061009822', ['configurations' => []]),
            'device association' => $devices->associations->associate('861265061009822', []),
            'device configurations' => $devices->updateConfigurations('861265061009822', []),
            'device request' => $devices->featureRequests->requestFeature('861265061009822', []),
            'login' => $auth->login(['username' => 'admin']),
            'license token' => $auth->licenseToken([]),
        ];

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
