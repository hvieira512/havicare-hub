<?php

namespace Tests\Integration\Registry;

use Hub\Api\Repository\DenylistRepository;
use Hub\Registry\Denylist;
use Tests\Support\MysqlDashboardTestCase;

final class DenylistTest extends MysqlDashboardTestCase
{
    private DenylistRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new DenylistRepository($this->createDashboardDatabase()->pdo());
    }

    public function testBlockPersistsAndContainsReflectsIt(): void
    {
        $denylist = new Denylist($this->repository);
        self::assertFalse($denylist->contains('357000000000001'));

        $denylist->block('357000000000001', 'four-p-touch', 'vizinho', 'admin');
        self::assertTrue($denylist->contains('357000000000001'));

        // Uma instância nova relê da base -- prova que ficou persistido, não só em memória.
        self::assertTrue((new Denylist($this->repository))->contains('357000000000001'));

        $denylist->unblock('357000000000001');
        self::assertFalse($denylist->contains('357000000000001'));
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
