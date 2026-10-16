<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Migration\JoyTechCatalog;
use Tests\Support\MysqlDashboardTestCase;

/** O tensiómetro da JoyTech nas bases que já existiam. */
final class JoyTechCatalogTest extends MysqlDashboardTestCase
{
    public function testTheTypeSupplierAndModelEnterOnce(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $pdo->exec("DELETE m FROM models m JOIN suppliers s ON s.id = m.supplier_id WHERE s.name = 'JoyTech'");

        (new JoyTechCatalog())->up($pdo);
        (new JoyTechCatalog())->up($pdo);

        self::assertSame([['HC-6292B', 'HC-6292B', 'blood_pressure_monitor', 'cbae0ad7a44f97f1d4993e551e03a28b.jpg']], $pdo->query("
            SELECT m.internal_model, m.commercial_name, m.device_type, m.image_path FROM models m
            JOIN suppliers s ON s.id = m.supplier_id WHERE s.name = 'JoyTech'
        ")->fetchAll(\PDO::FETCH_NUM));
    }
}
