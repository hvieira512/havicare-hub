<?php

declare(strict_types=1);

namespace Tests\Integration\Api;

use Hub\Api\Services\DashboardNotificationService;
use Hub\Api\Services\DenylistService;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Tests\Support\MysqlDashboardTestCase;

/**
 * Um corpo recusado diz que campo está mal, em `error.fields`. Ficam de fora os serviços cujo
 * corpo a capacidade declara em execução, o que não leva corpo, e o login de três formas.
 */
final class ValidationIsUniformTest extends MysqlDashboardTestCase
{
    public function testARefusedBodySaysWhichFieldIsWrong(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());

        $refusals = [
            'denylist' => (new DenylistService($db))->block([], 'admin'),
            'notifications' => (new DashboardNotificationService($db))->markRead([]),
        ];

        $without = [];
        foreach ($refusals as $name => $result) {
            if (($result['error']['code'] ?? null) !== 'invalid_request') {
                $without[] = "{$name}: não recusou um corpo vazio";
                continue;
            }
            if (!is_array($result['error']['fields'] ?? null) || $result['error']['fields'] === []) {
                $without[] = "{$name}: recusou sem dizer que campo";
            }
        }

        self::assertSame([], $without);
    }
}
