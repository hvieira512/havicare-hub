<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Migration\WhitelistReferencesModelAndLicense;
use PDO;
use Tests\Support\MysqlDashboardTestCase;

/** A whitelist com os nomes copiados passa a apontar para o modelo e para a licença, sem perder dispositivos. */
final class WhitelistReferencesModelAndLicenseTest extends MysqlDashboardTestCase
{
    public function testAnOldWhitelistPointsToItsModelAndLicense(): void
    {
        $pdo = $this->oldShapedDatabase();
        $pdo->exec("INSERT INTO whitelist (imei, supplier, model, device_type, license_id, company) VALUES
            ('865028000000401', '4P Touch', 'D46', 'watch', 1001, 'hitcare'),
            ('865028000000402', '4P Touch', 'D46', 'watch', NULL, NULL)");

        (new WhitelistReferencesModelAndLicense())->up($pdo);
        (new WhitelistReferencesModelAndLicense())->up($pdo);

        $rows = $pdo->query('
            SELECT w.imei, m.internal_model, l.license_id FROM whitelist w
            JOIN models m ON m.id = w.model_id LEFT JOIN licenses l ON l.id = w.license_ref_id ORDER BY w.imei
        ')->fetchAll(PDO::FETCH_NUM);
        self::assertSame([['865028000000401', 'D46', 1001], ['865028000000402', 'D46', null]], $rows);
        self::assertFalse($this->hasColumn($pdo, 'company'));
    }

    public function testADeviceWithoutAMatchStopsTheMigrationBeforeAnythingIsDropped(): void
    {
        $pdo = $this->oldShapedDatabase();
        $pdo->exec("INSERT INTO whitelist (imei, supplier, model, device_type, license_id, company) VALUES
            ('865028000000401', '4P Touch', 'D46', 'watch', 4242, 'hitcare')");

        try {
            (new WhitelistReferencesModelAndLicense())->up($pdo);
            self::fail('a licença 4242 não existe');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('865028000000401', $e->getMessage());
        }
        self::assertTrue($this->hasColumn($pdo, 'company'));
    }

    private function oldShapedDatabase(): PDO
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $companyId = (int)$pdo->query("SELECT id FROM companies WHERE name = 'hitcare'")->fetchColumn();
        if ($companyId === 0) {
            $pdo->exec("INSERT INTO companies (name) VALUES ('hitcare')");
            $companyId = (int)$pdo->lastInsertId();
        }
        $pdo->exec("INSERT IGNORE INTO licenses (company_id, license_id, name) VALUES ({$companyId}, 1001, 'gucc.dev')");
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('DELETE FROM whitelist');
        $pdo->exec('ALTER TABLE whitelist
            DROP FOREIGN KEY fk_whitelist_model,
            DROP FOREIGN KEY fk_whitelist_license,
            DROP INDEX idx_whitelist_model,
            DROP INDEX idx_whitelist_license_device_type,
            DROP COLUMN model_id,
            DROP COLUMN license_ref_id,
            ADD COLUMN supplier VARCHAR(96) NOT NULL AFTER imei,
            ADD COLUMN model VARCHAR(96) NOT NULL AFTER supplier,
            ADD COLUMN license_id INT UNSIGNED NULL AFTER device_type,
            ADD COLUMN company VARCHAR(96) NULL,
            ADD KEY idx_whitelist_supplier_model (supplier, model),
            ADD KEY idx_whitelist_license_device_type (license_id, device_type),
            ADD KEY idx_whitelist_company (company)');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        return $pdo;
    }

    private function hasColumn(PDO $pdo, string $column): bool
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whitelist' AND COLUMN_NAME = ?");
        $stmt->execute([$column]);

        return (int)$stmt->fetchColumn() > 0;
    }
}
