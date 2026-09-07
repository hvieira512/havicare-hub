<?php

namespace Tests\Integration\Api\Repository;

use Hub\Api\Repository\DenylistRepository;
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

    public function testAllListsBlockedIdentities(): void
    {
        $this->repository->add('357000000000002', 'four-p-touch', null, 'admin');
        $this->repository->add('357000000000003', 'qinglanst-radar', null, 'admin');

        $identities = array_column($this->repository->all(), 'identity');
        self::assertContains('357000000000002', $identities);
        self::assertContains('357000000000003', $identities);
    }
}
