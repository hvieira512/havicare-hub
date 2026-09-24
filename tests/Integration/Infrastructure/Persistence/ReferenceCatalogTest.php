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
        self::assertSame('Chamada de ajuda', $labels['ncs:help_call'] ?? null);
        // A mesma grandeza não muda de nome com o aparelho: o relógio já lhe chamava VFC.
        self::assertSame('VFC', $labels['bracelet:hrv'] ?? null);
        self::assertSame('VFC', $labels['watch:hrv'] ?? null);
    }

    /**
     * A ordem dentro de uma secção é alfabética pela etiqueta, que é o que quem lê tem à
     * frente. Antes era um inteiro escolhido à mão e guardado na base, invisível no ecrã: a
     * lista tinha uma ordem que não se explicava por nada do que lá estava.
     *
     * A ordem das secções não vem daqui -- é uma lista fixa na consulta -- e continua igual.
     */
    public function testCapabilitiesComeBackAlphabeticalWithinTheirSection(): void
    {
        $capabilities = new \Hub\Api\Repository\GenericCapabilityRepository(
            $this->createDashboardDatabase()->pdo(),
        );

        $labels = array_values(array_map(
            static fn(array $row): string => (string)$row['label'],
            array_filter(
                $capabilities->all('watch'),
                static fn(array $row): bool => $row['section'] === 'telemetry',
            ),
        ));

        $sorted = $labels;
        usort($sorted, static fn(string $left, string $right): int => strcmp(
            iconv('UTF-8', 'ASCII//TRANSLIT', mb_strtolower($left, 'UTF-8')) ?: $left,
            iconv('UTF-8', 'ASCII//TRANSLIT', mb_strtolower($right, 'UTF-8')) ?: $right,
        ));

        self::assertSame($sorted, $labels, 'a telemetria de um relógio sai fora de ordem');
        self::assertSame('Atividade (passos)', $labels[0] ?? null);
        // Em bytes o "VFC" vinha antes da "Versão", por a maiúscula pesar menos que a
        // minúscula. É o caso que distingue ordem portuguesa de ordem de tabela ASCII.
        self::assertGreaterThan(
            array_search('Versão do firmware', $labels, true),
            array_search('VFC', $labels, true),
        );
    }

    /** A ordem das secções é escolhida, e não alfabética: a telemetria vem antes da saúde. */
    public function testSectionsKeepTheirDeliberateOrder(): void
    {
        $capabilities = new \Hub\Api\Repository\GenericCapabilityRepository(
            $this->createDashboardDatabase()->pdo(),
        );

        $sections = array_values(array_unique(array_map(
            static fn(array $row): string => (string)$row['section'],
            $capabilities->all('watch'),
        )));

        self::assertSame(
            ['telemetry', 'health', 'contacts', 'alarms', 'settings_system'],
            $sections,
        );
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
            ['battery', 'change_required', 'diaper_condition', 'diaper_moisture', 'diaper_moisture_level', 'diaper_sensitivity', 'proximity'],
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

    /**
     * O dispensador entrou depois das duas bases existirem, e por isso tem duas origens que
     * têm de dar no mesmo: o seeder, numa base nova, e a migração, nas que já cá estavam.
     * Isto afirma a primeira.
     */
    public function testThePillDispenserCatalogueIsComplete(): void
    {
        $database = $this->createDashboardDatabase();
        $pdo = $database->pdo();

        $expected = [
            'alarm_ringtone',
            'alarm_volume',
            'battery',
            'calibrate_clock',
            'cells_remaining',
            'child_lock',
            // A ligação à rede é a mesma capacidade genérica que os gateways publicam, e não
            // um `device_status` com uma forma só deste aparelho.
            'connectivity',
            'device_fault',
            'device_language',
            'device_status',
            'dispense_now',
            'do_not_disturb',
            'early_dispense',
            // Chega no pacote de registo, e por isso não é pedível como as outras leituras.
            'firmware_version',
            'help_call',
            'humidity',
            'loaded_cells',
            // A mudança de estado de uma dose é acontecimento próprio: é o único sinal de uma
            // dose falhada, e viajava dentro da leitura dos nove, pelo canal sem garantia de
            // entrega.
            'medication_alarm_change',
            // A toma lê-se por aqui sem a chave de cifra: o `medication_intake` é o evento
            // rico e chega cifrado, este é o estado dos nove alarmes e chega em claro.
            'medication_alarm_status',
            'medication_intake',
            // O `medication_level` não está cá: era o juízo grosseiro do aparelho a dizer o
            // mesmo que a contagem de células, e sem número nenhum. É campo dela.
            'medication_period',
            'medication_reminders',
            'mute_alarm',
            // Três acções não entram: a reposição de fábrica, que devolvia o aparelho ao
            // servidor do fornecedor, desligar a cifra, que o firmware recusa sempre, e mudar
            // o servidor a que ele se liga, que é a única que nos pode fazer perdê-lo.
            'reset_tray',
            'restart_device',
            // Rodar até um compartimento e pausar a medicação não entram: estão na
            // especificação da série M2, mas este firmware recusa-as e a descoberta de
            // parâmetros não as anuncia.
            'retrieval_timeout',
            'retrieval_warning',
            'storage_environment',
            // O cartão SIM não está cá: o CCID é um identificador que nunca muda, ninguém o
            // consulta na dashboard, e cada leitura de estado repetia-o na lista de eventos.
            // Uma por família: o aparelho separa configuração, estado e controlo, e cada
            // pergunta é um pacote próprio.
            'sync_configuration',
            'temperature',
            'time_zone',
            // O trinco do prato saiu de dentro do estado do dispositivo: destrancado é um
            // estado sobre que se age. Não é a «tampa» — essa é a leitura do tipo 01.
            'tray_lock',
        ];

        self::assertSame(
            $expected,
            array_values(array_unique(array_map('strval', $pdo->query(
                "SELECT capability_key FROM capabilities WHERE device_type = 'pill_dispenser' ORDER BY capability_key"
            )->fetchAll(\PDO::FETCH_COLUMN))))
        );

        // Doze configuráveis e sete pedíveis. Uma acção pede-se e não se configura, e por isso
        // as duas bandeiras nunca estão ligadas ao mesmo tempo.
        //
        // As sete leituras que o `0x07` enche não se pedem sozinhas: a trama pede-as sempre a
        // todas, e quem carrega o botão é o `device_status`.
        self::assertSame(
            ['12', '7'],
            array_map('strval', $pdo->query("
                SELECT
                    SUM(is_configurable = 1) AS configuraveis,
                    SUM(is_requestable = 1) AS pediveis
                FROM capabilities WHERE device_type = 'pill_dispenser'
            ")->fetch(\PDO::FETCH_NUM))
        );
        self::assertSame(
            0,
            (int)$pdo->query("
                SELECT COUNT(*) FROM capabilities
                WHERE device_type = 'pill_dispenser' AND is_configurable = 1 AND is_requestable = 1
            ")->fetchColumn()
        );

        $db = ApiDataAccess::fromDatabase($database);
        $dispenser = $db->models->find('Zayata', 'M228');
        self::assertIsArray($dispenser);
        self::assertSame('pill_dispenser', $dispenser['device_type']);
        // O nome comercial não repete o fornecedor, que a dashboard já mostra ao lado.
        self::assertSame('M228', $dispenser['commercial_name']);
        self::assertSame($expected, $db->modelCapabilities->enabledFeaturesForModelId((int)$dispenser['id']));
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
            ['battery', 'change_required', 'diaper_condition', 'diaper_moisture', 'diaper_moisture_level', 'diaper_sensitivity', 'proximity'],
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
