<?php

declare(strict_types=1);

namespace Tests\Integration\Api\Services;

use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Api\Services\DeviceService;
use Hub\State\DeviceStoreContract;
use Hub\Device\DeviceHubServer;
use Hub\Registry\Whitelist;
use PDO;
use PDOStatement;
use Tests\Support\MysqlDashboardTestCase;

/** Quantas vezes o detalhe de um dispositivo o vai buscar. É o ecrã mais aberto da dashboard. */
final class DeviceShowQueryCountTest extends MysqlDashboardTestCase
{
    /** @var list<string> */
    private array $queries = [];

    public function testTheDeviceDetailResolvesTheDeviceOnlyOnce(): void
    {
        $pdo = $this->countingPdo($this->createDashboardDatabase()->pdo());
        $db = ApiDataAccess::fromPdo($pdo);

        $imei = '861265061009822';
        $whitelist = new Whitelist(null, $db->whitelist);
        $whitelist->register($imei, 'Vivistar', 'L08 Pro', 'watch', 1001, '', $imei, 'hitcare');

        $service = new DeviceService(
            $this->createStub(DeviceStoreContract::class),
            $whitelist,
            $this->createStub(DeviceHubServer::class),
            $db,
        );

        $this->queries = [];
        $service->show($imei);

        self::assertSame(
            1,
            count(array_filter(
                $this->queries,
                static fn (string $sql): bool => str_contains($sql, 'FROM whitelist w'),
            )),
            'o dispositivo é resolvido uma vez por pedido',
        );
    }

    private function countingPdo(PDO $inner): PDO
    {
        $test = $this;

        return new class ($inner, $test) extends PDO {
            public function __construct(private PDO $inner, private DeviceShowQueryCountTest $test)
            {
            }

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                $this->test->record($query);

                return $this->inner->prepare($query, $options);
            }

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetch): PDOStatement|false
            {
                $this->test->record($query);

                return $this->inner->query($query, $fetchMode, ...$fetch);
            }
        };
    }

    public function record(string $query): void
    {
        $this->queries[] = $query;
    }
}
