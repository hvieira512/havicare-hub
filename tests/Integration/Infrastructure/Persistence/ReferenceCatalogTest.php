<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Hub\Api\Repository\ApiDataAccess;
use Tests\Support\MysqlDashboardTestCase;

/**
 * O catálogo de referência de uma base de dados acabada de construir.
 *
 * Estes factos -- que etiqueta tem cada capacidade, que capacidades tem cada tipo de
 * aparelho, o que o template de cada modelo liga -- são o destino, e não o caminho: uma base
 * nova tem de nascer aqui a partir do `CapabilityCatalog` e do `SupplierCapabilityTemplate`.
 *
 * Se alguém mudar o catálogo em código e partir uma destas afirmações, parte-se aqui e não
 * em produção passado um deploy.
 */
final class ReferenceCatalogTest extends MysqlDashboardTestCase
{
    public function testCapabilityLabelsAreInPortuguese(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();

        $labels = $pdo->query("
            SELECT CONCAT(device_type, ':', capability_key), label
            FROM capabilities
        ")->fetchAll(\PDO::FETCH_KEY_PAIR);

        self::assertSame('Frequência cardíaca', $labels['watch:heart_rate'] ?? null);
        self::assertSame('Presença', $labels['radar:presence'] ?? null);
        self::assertSame('Chamada de ajuda', $labels['ncs:pager_call'] ?? null);
    }

    public function testTheCatalogueHasNoCapabilityTheHubCannotServe(): void
    {
        // O tempo saiu do catálogo quando se percebeu que nenhum protocolo o entrega.
        $pdo = $this->createDashboardDatabase()->pdo();

        self::assertSame(
            0,
            (int)$pdo->query("SELECT COUNT(*) FROM capabilities WHERE capability_key = 'weather_data'")->fetchColumn()
        );
    }

    public function testGatewayAndDiaperSensorCataloguesAreComplete(): void
    {
        $database = $this->createDashboardDatabase();
        $pdo = $database->pdo();

        self::assertSame(
            ['battery', 'connectivity', 'location'],
            array_values(array_unique(array_map('strval', $pdo->query(
                "SELECT capability_key FROM capabilities WHERE device_type = 'gateway' ORDER BY capability_key"
            )->fetchAll(\PDO::FETCH_COLUMN))))
        );
        self::assertSame(
            ['battery', 'change_required', 'diaper_condition', 'diaper_moisture', 'diaper_moisture_level', 'diaper_sensitivity'],
            array_values(array_unique(array_map('strval', $pdo->query(
                "SELECT capability_key FROM capabilities WHERE device_type = 'diaper_sensor' ORDER BY capability_key"
            )->fetchAll(\PDO::FETCH_COLUMN))))
        );
        self::assertSame(
            ['diaper_condition', 'diaper_moisture', 'diaper_moisture_level'],
            $pdo->query("
                SELECT capability_key FROM capabilities
                WHERE device_type = 'diaper_sensor' AND section = 'telemetry' AND capability_key LIKE 'diaper_%'
                ORDER BY capability_key
            ")->fetchAll(\PDO::FETCH_COLUMN)
        );
        self::assertContains('gateway_device_links', $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testEachModelTemplateMatchesWhatTheHardwareHas(): void
    {
        $database = $this->createDashboardDatabase();
        $db = ApiDataAccess::fromDatabase($database);

        $mkgw3 = $db->models->find('MOKO', 'MKGW3');
        $mkgw4 = $db->models->find('MOKO', 'MKGW4');
        $sensor = $db->models->find('MONIT', 'MECS-PRO');
        self::assertIsArray($mkgw3);
        self::assertIsArray($mkgw4);
        self::assertIsArray($sensor);

        // O MKGW3 é alimentado por PoE e não tem GPS; o MKGW4 tem bateria e localiza-se.
        self::assertSame(
            ['connectivity'],
            $db->modelCapabilities->enabledFeaturesForModelId((int)$mkgw3['id'])
        );
        self::assertSame(
            ['battery', 'connectivity', 'location'],
            $db->modelCapabilities->enabledFeaturesForModelId((int)$mkgw4['id'])
        );
        self::assertSame(
            ['battery', 'change_required', 'diaper_condition', 'diaper_moisture', 'diaper_moisture_level', 'diaper_sensitivity'],
            $db->modelCapabilities->enabledFeaturesForModelId((int)$sensor['id'])
        );
    }

    public function testTheWatchCatalogueCarriesNoInternalSyncEntries(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();

        $rows = $pdo->query("
            SELECT capability_key, section, label, is_configurable, is_requestable
            FROM capabilities
            WHERE device_type = 'watch'
        ")->fetchAll(\PDO::FETCH_UNIQUE | \PDO::FETCH_ASSOC);

        // Estas eram mecanismos do protocolo a passar por capacidades do aparelho.
        self::assertArrayNotHasKey('device_binding', $rows);
        self::assertArrayNotHasKey('device_settings_sync', $rows);
        self::assertArrayNotHasKey('call_log', $rows);
        self::assertArrayNotHasKey('sms', $rows);
        self::assertArrayNotHasKey('ecg_analysis', $rows);
        self::assertArrayNotHasKey('call_in_restriction', $rows);

        self::assertSame('settings_system', $rows['device_state']['section'] ?? null);
        self::assertSame('contacts', $rows['whitelist_enabled']['section'] ?? null);
        self::assertSame('Alerta de remoção do relógio', $rows['remove_watch_alarm']['label'] ?? null);

        // Uma acção pede-se, não se configura.
        self::assertSame(0, (int)($rows['push_message']['is_configurable'] ?? -1));
        self::assertSame(1, (int)($rows['push_message']['is_requestable'] ?? -1));
        self::assertSame(0, (int)($rows['make_call']['is_configurable'] ?? -1));
        self::assertSame(1, (int)($rows['make_call']['is_requestable'] ?? -1));
    }
}
