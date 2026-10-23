<?php

declare(strict_types=1);

namespace Tests\Integration\Api\Services;

use Hub\Api\Http\ApiError;
use Hub\Api\Services\DeviceService;
use Hub\Api\Services\ModelService;
use Hub\Device\DeviceHubServer;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Registry\Whitelist;
use Hub\State\DeviceStore;
use Tests\Support\Doubles\InMemoryRedisClient;
use Tests\Support\MysqlDashboardTestCase;

/** Apagar o que não existe diz que não existe, em todos os recursos, e não responde como um apagar certo. */
final class DeleteOfSomethingAbsentTest extends MysqlDashboardTestCase
{
    public function testDeletingADeviceThatIsNotRegisteredIsNotFound(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $service = new DeviceService(
            new DeviceStore(new InMemoryRedisClient()),
            new Whitelist($db->whitelist),
            $this->createStub(DeviceHubServer::class),
            $db,
        );

        self::assertSame(404, $this->statusOf($service->delete('865028000000999')));
    }

    public function testDeletingAModelThatDoesNotExistIsNotFound(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());

        self::assertSame(404, $this->statusOf((new ModelService($db))->delete(999999)));
    }

    /**
     * Pelo estado HTTP e não pelo código de erro, que cada recurso nomeia à sua maneira.
     *
     * @param array<string, mixed> $result
     */
    private function statusOf(array $result): int
    {
        return ApiError::statusForCode((string)($result['error']['code'] ?? ''));
    }
}
