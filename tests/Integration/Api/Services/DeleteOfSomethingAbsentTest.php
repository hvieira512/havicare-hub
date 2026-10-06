<?php

declare(strict_types=1);

namespace Tests\Integration\Api\Services;

use Hub\Api\Http\ApiError;
use Hub\Api\Services\DeviceService;
use Hub\Api\Services\ModelService;
use Hub\Device\DeviceHubServer;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Registry\Whitelist;
use Hub\State\DeviceStoreContract;
use Tests\Support\MysqlDashboardTestCase;

/**
 * Apagar o que não existe diz que não existe, em todos os recursos: quem apagou o aparelho
 * errado não pode receber a mesma resposta de quem apagou o certo.
 */
final class DeleteOfSomethingAbsentTest extends MysqlDashboardTestCase
{
    public function testDeletingADeviceThatIsNotRegisteredIsNotFound(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $service = new DeviceService(
            $this->createStub(DeviceStoreContract::class),
            new Whitelist(null, $db->whitelist),
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
     * Pelo estado HTTP e não pelo código: cada recurso tem o seu nome de erro -- uns dizem
     * `not_found` e outros `{recurso}_not_found` --, e o que tem de ser igual é a resposta.
     *
     * @param array<string, mixed> $result
     */
    private function statusOf(array $result): int
    {
        return ApiError::statusForCode((string)($result['error']['code'] ?? ''));
    }
}
