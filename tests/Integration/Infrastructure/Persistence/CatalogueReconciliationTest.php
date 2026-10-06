<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Infrastructure\Persistence\ReferenceCatalogSeeder;
use Tests\Support\MysqlDashboardTestCase;

/**
 * O catálogo em código é a verdade, e a base segue-o a cada arranque: o PHP decide o canal do
 * MQTT pelo `isEvent` e a base decide o que a dashboard mostra.
 */
final class CatalogueReconciliationTest extends MysqlDashboardTestCase
{
    public function testARenamedLabelGoesBackToWhatTheCodeDeclares(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $pdo->exec("
            UPDATE capabilities
            SET label = 'Nome à mão'
            WHERE device_type = 'pill_dispenser' AND capability_key = 'battery'
        ");

        (new ReferenceCatalogSeeder())->reconcileCapabilities($pdo);

        self::assertSame('Bateria', $this->label($pdo, 'pill_dispenser', 'battery'));
    }

    /** A bandeira de pedível também: é ela que decide se o mosaico responde ao clique. */
    public function testAStaleRequestableFlagIsPutBack(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $pdo->exec("
            UPDATE capabilities
            SET is_requestable = 0
            WHERE device_type = 'pill_dispenser' AND capability_key = 'sync_configuration'
        ");

        (new ReferenceCatalogSeeder())->reconcileCapabilities($pdo);

        self::assertSame(1, $this->flag($pdo, 'pill_dispenser', 'sync_configuration'));
    }

    /** Uma capacidade que a base não tem entra, sem precisar de migração. */
    public function testACapabilityMissingFromTheDatabaseIsInserted(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $pdo->exec("
            DELETE FROM capabilities
            WHERE device_type = 'pill_dispenser' AND capability_key = 'cells_remaining'
        ");

        (new ReferenceCatalogSeeder())->reconcileCapabilities($pdo);

        self::assertSame('Células restantes', $this->label($pdo, 'pill_dispenser', 'cells_remaining'));
    }

    public function testACapabilityTheCodeNoLongerDeclaresIsRemoved(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $pdo->exec("
            INSERT INTO capabilities (device_type, section, capability_key, label, is_configurable, is_requestable)
            VALUES ('pill_dispenser', 'telemetry', 'inventada', 'Inventada', 0, 0)
        ");

        (new ReferenceCatalogSeeder())->reconcileCapabilities($pdo);

        self::assertSame(0, (int)$pdo->query("
            SELECT COUNT(*) FROM capabilities
            WHERE device_type = 'pill_dispenser' AND capability_key = 'inventada'
        ")->fetchColumn());
    }

    /**
     * A `capabilities` é o catálogo, que ninguém edita fora do código; a `model_capabilities` é
     * a configuração de quem opera, e não se toca.
     */
    public function testWhatEachModelHasEnabledIsLeftAlone(): void
    {
        $database = $this->createDashboardDatabase();
        $pdo = $database->pdo();
        $modelId = (int)$pdo->query("
            SELECT m.id FROM models m JOIN suppliers s ON s.id = m.supplier_id
            WHERE s.name = 'Zayata' AND m.internal_model = 'M228'
        ")->fetchColumn();

        $pdo->exec("
            UPDATE model_capabilities SET enabled = 0
            WHERE model_id = {$modelId} AND capability_key = 'humidity'
        ");

        (new ReferenceCatalogSeeder())->reconcileCapabilities($pdo);

        self::assertSame(0, (int)$pdo->query("
            SELECT enabled FROM model_capabilities
            WHERE model_id = {$modelId} AND capability_key = 'humidity'
        ")->fetchColumn());
    }

    /** Sem o tipo, a primeira capacidade declarada para ele rebenta a chave estrangeira. */
    public function testADeviceTypeMissingFromTheDatabaseIsInserted(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $pdo->exec("DELETE FROM whitelist WHERE device_type = 'pill_dispenser'");
        $pdo->exec("DELETE FROM models WHERE device_type = 'pill_dispenser'");
        $pdo->exec("DELETE FROM capabilities WHERE device_type = 'pill_dispenser'");
        $pdo->exec("DELETE FROM device_types WHERE device_type = 'pill_dispenser'");

        (new ReferenceCatalogSeeder())->reconcileCapabilities($pdo);

        self::assertSame(1, (int)$pdo->query("
            SELECT COUNT(*) FROM device_types WHERE device_type = 'pill_dispenser'
        ")->fetchColumn());
        self::assertSame('Bateria', $this->label($pdo, 'pill_dispenser', 'battery'));
    }

    /** E no fim a base diz exactamente o que o código declara, toda ela. */
    public function testTheWholeCatalogueMatchesAfterReconciling(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $pdo->exec("UPDATE capabilities SET label = CONCAT(label, ' (torto)'), is_requestable = 0");

        (new ReferenceCatalogSeeder())->reconcileCapabilities($pdo);

        $stored = [];
        foreach ($pdo->query('SELECT device_type, capability_key, section, label, is_configurable, is_requestable FROM capabilities') as $row) {
            $stored[$row['device_type'] . ':' . $row['capability_key']] = [
                (string)$row['section'],
                (string)$row['label'],
                (bool)$row['is_configurable'],
                (bool)$row['is_requestable'],
            ];
        }

        $declared = [];
        foreach (CapabilityCatalog::definitions() as $definition) {
            $declared[$definition['deviceType'] . ':' . $definition['key']] = [
                (string)$definition['section'],
                (string)$definition['label'],
                (bool)($definition['isConfigurable'] ?? false),
                (bool)($definition['isRequestable'] ?? false),
            ];
        }

        ksort($stored);
        ksort($declared);
        self::assertSame($declared, $stored);
    }

    private function label(\PDO $pdo, string $deviceType, string $key): ?string
    {
        $statement = $pdo->prepare('SELECT label FROM capabilities WHERE device_type = ? AND capability_key = ?');
        $statement->execute([$deviceType, $key]);
        $label = $statement->fetchColumn();

        return $label === false ? null : (string)$label;
    }

    private function flag(\PDO $pdo, string $deviceType, string $key): ?int
    {
        $statement = $pdo->prepare('SELECT is_requestable FROM capabilities WHERE device_type = ? AND capability_key = ?');
        $statement->execute([$deviceType, $key]);
        $flag = $statement->fetchColumn();

        return $flag === false ? null : (int)$flag;
    }
}
