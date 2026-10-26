<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Tests\Support\MysqlDashboardTestCase;

final class DatabaseReconnectTest extends MysqlDashboardTestCase
{
    /** O servidor só se descobre morto ao preparar se a preparação for nativa. */
    public function testAPreparedReadSurvivesTheServerDroppingTheConnection(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $connectionId = (int)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn();

        $this->pdoForDatabase($this->databaseName($pdo))->exec('KILL ' . $connectionId);

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM device_types');
        $stmt->execute();

        self::assertGreaterThan(0, (int)$stmt->fetchColumn());
    }
}
