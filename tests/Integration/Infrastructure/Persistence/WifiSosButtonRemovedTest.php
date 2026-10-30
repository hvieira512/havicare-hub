<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Migration\WifiSosButtonRemoved;
use PDO;
use Tests\Support\MysqlDashboardTestCase;

/** A pulseira SOS Wi-Fi não vai para produção: sai o fornecedor PST, com os modelos e os aparelhos dele. */
final class WifiSosButtonRemovedTest extends MysqlDashboardTestCase
{
    public function testThePstSupplierLeavesWithItsModelsAndDevices(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $pdo->exec("INSERT IGNORE INTO suppliers (name) VALUES ('PST')");
        $pdo->exec("
            INSERT IGNORE INTO models (supplier_id, internal_model, commercial_name, device_type, image_path)
            SELECT id, 'CF103W', 'CF103W', 'bracelet', '' FROM suppliers WHERE name = 'PST'
        ");
        $pdo->exec("
            INSERT IGNORE INTO model_capabilities (model_id, device_type, capability_key, enabled)
            SELECT m.id, 'bracelet', 'help_call', 1 FROM models m WHERE m.internal_model = 'CF103W'
        ");
        $pdo->exec("
            INSERT INTO whitelist (imei, model_id, device_type, device_id)
            SELECT m.id, m.id, 'bracelet', 'bf7cbe84a6199e98168qvb' FROM models m WHERE m.internal_model = 'CF103W'
        ");
        $models = (int)$pdo->query('SELECT COUNT(*) FROM models')->fetchColumn();

        (new WifiSosButtonRemoved())->up($pdo);
        (new WifiSosButtonRemoved())->up($pdo);

        self::assertSame([], $pdo->query("SELECT id FROM suppliers WHERE name = 'PST'")->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame($models - 1, (int)$pdo->query('SELECT COUNT(*) FROM models')->fetchColumn());
        self::assertSame([], $pdo->query("SELECT imei FROM whitelist WHERE device_id = 'bf7cbe84a6199e98168qvb'")->fetchAll(PDO::FETCH_COLUMN));
    }
}
