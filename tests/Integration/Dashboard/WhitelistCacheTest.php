<?php

declare(strict_types=1);

namespace Tests\Integration\Dashboard;

use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Registry\Whitelist;
use Tests\Support\MysqlDashboardTestCase;

final class WhitelistCacheTest extends MysqlDashboardTestCase
{
    /** Uma recarga que falha não pode tornar desconhecidos os dispositivos que já se conheciam. */
    public function testAFailedReloadKeepsTheDevicesAlreadyKnown(): void
    {
        $database = $this->createDashboardDatabase();
        $db = ApiDataAccess::fromDatabase($database);
        $db->whitelist->register('861265061009822', 'Vivistar', 'L08 Pro', 'watch', 1001, '', '', 'hitcare');
        $whitelist = new Whitelist($db->whitelist, databaseCacheTtlSeconds: 0);

        $database->pdo()->exec('RENAME TABLE whitelist TO whitelist_away');

        self::assertTrue($whitelist->isAuthorized('861265061009822'));
    }

    /** O alias resolve-se na lista em memória: uma consulta por mensagem bloqueava o loop. */
    public function testAnAliasResolvesWithoutTheDatabase(): void
    {
        $database = $this->createDashboardDatabase();
        $db = ApiDataAccess::fromDatabase($database);
        $db->whitelist->register('bea6c3dd8e02', 'Voerka', 'W812', 'ncs', 1001, '', '3e78ec2b0fb7', 'hitcare');
        $whitelist = new Whitelist($db->whitelist, databaseCacheTtlSeconds: 3600);

        $database->pdo()->exec('RENAME TABLE whitelist TO whitelist_away');

        self::assertSame('bea6c3dd8e02', $whitelist->resolve('3e78ec2b0fb7', 'ncs')['imei'] ?? null);
    }
}
