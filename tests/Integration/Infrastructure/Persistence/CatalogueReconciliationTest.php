<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Infrastructure\Persistence\ReferenceCatalogSeeder;
use Tests\Support\MysqlDashboardTestCase;

/**
 * O catálogo em código é a verdade, e a base segue-o a cada arranque.
 *
 * Eram duas cópias com dois leitores — o PHP decide o canal do MQTT pelo `isEvent`, a base
 * decide o que a dashboard mostra — e o semeador só corria em base vazia. Numa base existente
 * só uma migração escrita à mão as voltava a juntar, e uma que faltasse não dava erro nenhum:
 * o hub passava a tratar uma capacidade que o ecrã nunca mostrava.
 *
 * Em produção isso já tinha acontecido em dez linhas, uma delas a bandeira de pedível da
 * bateria da pulseira.
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
            WHERE device_type = 'pill_dispenser' AND capability_key = 'device_status'
        ");

        (new ReferenceCatalogSeeder())->reconcileCapabilities($pdo);

        self::assertSame(1, $this->flag($pdo, 'pill_dispenser', 'device_status'));
    }

    /** Uma capacidade que a base não tem entra, sem precisar de migração. */
    public function testACapabilityMissingFromTheDatabaseIsInserted(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $pdo->exec("
            DELETE FROM capabilities
            WHERE device_type = 'pill_dispenser' AND capability_key = 'lid_state'
        ");

        (new ReferenceCatalogSeeder())->reconcileCapabilities($pdo);

        self::assertSame('Tampa', $this->label($pdo, 'pill_dispenser', 'lid_state'));
    }

    /**
     * Uma que o código já não declara sai.
     *
     * Sem isto, uma capacidade removida ficava na base a ser mostrada no ecrã enquanto o hub
     * nunca mais publicava nada por ela.
     */
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
     * O que cada modelo tem ligado é escolha de quem opera, e não se toca.
     *
     * É a distinção que faz esta reconciliação ser segura: a `capabilities` é o catálogo, que
     * ninguém edita fora do código; a `model_capabilities` é a configuração, e essa é dele.
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
