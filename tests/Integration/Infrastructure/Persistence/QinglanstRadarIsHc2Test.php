<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Migration\QinglanstRadarIsHc2;
use Tests\Support\MysqlDashboardTestCase;

/** O radar da Qinglanst passa a chamar-se pelo modelo que a cloud devolve, o HC2. */
final class QinglanstRadarIsHc2Test extends MysqlDashboardTestCase
{
    public function testTheModelAndItsRadarsAreRenamed(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $pdo->exec("UPDATE models m JOIN suppliers s ON s.id = m.supplier_id SET m.internal_model = 'RD-V1'
            WHERE s.name = 'Qinglanst'");
        $pdo->exec("INSERT INTO whitelist (imei, supplier, model, device_type, license_id)
            VALUES ('AD8A613B0493', 'Qinglanst', 'RD-V1', 'radar', 1001)");

        (new QinglanstRadarIsHc2())->up($pdo);

        self::assertSame(['HC2'], $pdo->query("SELECT m.internal_model FROM models m
            JOIN suppliers s ON s.id = m.supplier_id WHERE s.name = 'Qinglanst'")->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame('W01', $pdo->query("SELECT m.commercial_name FROM models m
            JOIN suppliers s ON s.id = m.supplier_id WHERE s.name = 'Qinglanst'")->fetchColumn());
        self::assertSame('HC2', $pdo->query("SELECT model FROM whitelist WHERE imei = 'AD8A613B0493'")->fetchColumn());
    }

    /** Numa base semeada já com o HC2, só os radares da whitelist ficam por mudar; correr duas vezes não muda nada. */
    public function testRadarsOfABaseAlreadySeededWithHc2AreRenamed(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $pdo->exec("INSERT INTO whitelist (imei, supplier, model, device_type, license_id)
            VALUES ('AD8A613B0493', 'Qinglanst', 'RD-V1', 'radar', 1001)");

        (new QinglanstRadarIsHc2())->up($pdo);
        (new QinglanstRadarIsHc2())->up($pdo);

        self::assertSame(['HC2'], $pdo->query("SELECT m.internal_model FROM models m
            JOIN suppliers s ON s.id = m.supplier_id WHERE s.name = 'Qinglanst'")->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame('HC2', $pdo->query("SELECT model FROM whitelist WHERE imei = 'AD8A613B0493'")->fetchColumn());
    }
}
