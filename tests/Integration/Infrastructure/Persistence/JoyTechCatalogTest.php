<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Migration\JoyTechCatalog;
use Hub\Infrastructure\Persistence\Migration\JoyTechThermometerCatalog;
use Tests\Support\MysqlDashboardTestCase;

/** O tensiómetro e o termómetro da JoyTech nas bases que já existiam. */
final class JoyTechCatalogTest extends MysqlDashboardTestCase
{
    public function testTheTypeSupplierAndModelEnterOnce(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $pdo->exec("DELETE m FROM models m JOIN suppliers s ON s.id = m.supplier_id WHERE s.name = 'JoyTech'");

        foreach ([new JoyTechCatalog(), new JoyTechThermometerCatalog(), new JoyTechCatalog(), new JoyTechThermometerCatalog()] as $migration) {
            $migration->up($pdo);
        }

        self::assertSame([
            ['HC-3018B', 'HC-3018B', 'thermometer', '82f686a2fa076ea0bebc7e7cb7a9d676.jpg'],
            ['HC-6292B', 'HC-6292B', 'blood_pressure_monitor', 'cbae0ad7a44f97f1d4993e551e03a28b.jpg'],
        ], $pdo->query("
            SELECT m.internal_model, m.commercial_name, m.device_type, m.image_path FROM models m
            JOIN suppliers s ON s.id = m.supplier_id WHERE s.name = 'JoyTech' ORDER BY m.internal_model
        ")->fetchAll(\PDO::FETCH_NUM));
    }
}
