<?php

declare(strict_types=1);

namespace Tests\Integration\Api\Repository;

use Hub\Infrastructure\Persistence\Repository\DenylistRepository;
use Hub\Registry\Denylist;
use PDO;
use Tests\Support\MysqlDashboardTestCase;

final class DenylistRepositoryTest extends MysqlDashboardTestCase
{
    private DenylistRepository $repository;
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->createDashboardDatabase()->pdo();
        $this->repository = new DenylistRepository($this->pdo);
    }

    public function testAddThenExistsThenRemove(): void
    {
        self::assertFalse($this->repository->exists('357000000000001'));

        $this->repository->add('357000000000001', 'four-p-touch', 'vizinho', 'admin');
        self::assertTrue($this->repository->exists('357000000000001'));

        self::assertTrue($this->repository->remove('357000000000001'));
        self::assertFalse($this->repository->exists('357000000000001'));
        // Remover uma identidade que já não está devolve falso -- é o sinal do 404 no unblock.
        self::assertFalse($this->repository->remove('357000000000001'));
    }

    public function testReblockingRefreshesReasonWithoutDuplicating(): void
    {
        $this->repository->add('AA:BB:CC:DD:EE:FF', 'moko-gateway', 'primeiro', 'admin');
        $this->repository->add('AA:BB:CC:DD:EE:FF', 'moko-gateway', 'segundo', 'outro');

        $rows = $this->repository->all();
        self::assertCount(1, $rows);
        self::assertSame('AA:BB:CC:DD:EE:FF', $rows[0]['identity']);
        self::assertSame('segundo', $rows[0]['note']);
        self::assertSame('outro', $rows[0]['created_by']);
    }

    public function testRefreshPicksUpAnExternalBlockWithinTtl(): void
    {
        // TTL zero: cada `contains` relê. Simula o bloqueio feito noutro caminho (a API) a
        // tornar-se visível ao caminho da ingestão.
        $denylist = new Denylist($this->repository, ttlSeconds: 0);
        self::assertFalse($denylist->contains('357000000000009'));

        $this->repository->add('357000000000009', 'four-p-touch', null, 'admin');
        self::assertTrue($denylist->contains('357000000000009'));
    }

    public function testEmptyIdentityIsNeverBlocked(): void
    {
        self::assertFalse((new Denylist($this->repository))->contains(''));
    }
}
