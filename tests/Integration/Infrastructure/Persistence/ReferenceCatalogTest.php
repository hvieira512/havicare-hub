<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Tests\Support\MysqlDashboardTestCase;

/**
 * O catálogo de referência de uma base acabada de construir, que nasce do `CapabilityCatalog` e
 * do `SupplierCapabilityTemplate`: uma mudança que os parta parte aqui, e não em produção.
 */
final class ReferenceCatalogTest extends MysqlDashboardTestCase
{
    /**
     * A ordem dentro de uma secção é alfabética pela etiqueta, que é o que quem lê tem à frente;
     * a ordem das secções é uma lista fixa na consulta.
     */
    public function testCapabilitiesComeBackAlphabeticalWithinTheirSection(): void
    {
        $capabilities = new \Hub\Infrastructure\Persistence\Repository\GenericCapabilityRepository(
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
        self::assertSame('Atividade', $labels[0] ?? null);
        // Em bytes o «VFC» viria antes da «Versão», por a maiúscula pesar menos: é o caso que
        // distingue a ordem portuguesa da ordem ASCII.
        self::assertGreaterThan(
            array_search('Versão do firmware', $labels, true),
            array_search('VFC', $labels, true),
        );
    }

    /** A ordem das secções é escolhida, e não alfabética: a telemetria vem antes da saúde. */
    public function testSectionsKeepTheirDeliberateOrder(): void
    {
        $capabilities = new \Hub\Infrastructure\Persistence\Repository\GenericCapabilityRepository(
            $this->createDashboardDatabase()->pdo(),
        );

        $sections = array_values(array_unique(array_map(
            static fn(array $row): string => (string)$row['section'],
            $capabilities->all('watch'),
        )));

        self::assertSame(
            ['telemetry', 'health', 'contacts', 'alarms', 'reminders', 'settings_system'],
            $sections,
        );
    }

    public function testDiaperReadingsAreTelemetryAndGatewayLinksHaveATable(): void
    {
        $database = $this->createDashboardDatabase();
        $pdo = $database->pdo();

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
     * O dispensador tem duas origens que têm de dar no mesmo: o seeder, numa base nova, e a
     * migração, nas existentes. Isto afirma a primeira.
     */
    public function testThePillDispenserCatalogueIsComplete(): void
    {
        $database = $this->createDashboardDatabase();
        $pdo = $database->pdo();

        $expected = [
            'alarm_ringtone',
            'alarm_volume',
            // O ar onde o aparelho está, e não uma pessoa: a spec dá o `0x810E` como INT8S de
            // -40 a 120 graus inteiros.
            'ambient_humidity',
            'ambient_temperature',
            // O aparelho acerta-se sozinho, sem esperar pelo `calibrate_clock`.
            'auto_clock',
            'battery',
            'calibrate_clock',
            'cells_remaining',
            'child_lock',
            // A ligação à rede é a mesma capacidade genérica que os gateways publicam, e não
            // um `device_status` com uma forma só deste aparelho.
            'connectivity',
            'date_format',
            'device_fault',
            'device_language',
            // O `0x07` pede as `STATUS_TAGS` todas, e a resposta enche as sete leituras. É a
            // única pedível entre elas, e pede-se como qualquer outra.
            'device_status',
            'dispense_now',
            'do_not_disturb',
            // A janela configura-se; o estado é outra coisa, e o terceiro valor dele — ligado
            // e a silenciar agora — é o que a configuração sozinha não sabe dizer.
            'early_dispense',
            // O interruptor que decide se o botão do aparelho chega a pedir ajuda. Fica ao
            // lado do `help_call`, que é o evento que ele produz.
            'emergency_call',
            // Chega no pacote de registo, e por isso não é pedível como as outras leituras.
            'firmware_version',
            'help_call',
            'key_tone',
            'loaded_cells',
            'low_battery',
            // A mudança de estado de uma dose é acontecimento próprio: é o único sinal de uma
            // dose falhada, e precisa de canal com garantia de entrega.
            'medication_alarm_change',
            // A toma lê-se por aqui sem a chave de cifra: o `medication_intake` é o evento
            // rico e chega cifrado, este é o estado dos nove alarmes e chega em claro.
            'medication_alarm_status',
            'medication_intake',
            // O `medication_level` não está cá: é o juízo grosseiro do aparelho a dizer o
            // mesmo que a contagem de células, e sem número nenhum.
            'medication_period',
            'medication_reminders',
            // Decide se uma dose já dada como falhada continua acessível, e por isso decide
            // se o desfecho `abnormal` do evento de toma chega a existir.
            'missed_dispense',
            'mute_alarm',
            // Ficam de fora a reposição de fábrica, que devolve o aparelho ao fornecedor, desligar
            // a cifra, que o firmware recusa, e mudar o servidor, que nos pode fazer perdê-lo.
            'reset_tray',
            'restart_device',
            // Rodar até um compartimento e pausar a medicação ficam de fora: este firmware
            // recusa-as e a descoberta de parâmetros não as anuncia.
            'retrieval_timeout',
            'retrieval_warning',
            'storage_environment',
            // Sem o CCID do SIM, que nunca muda e ninguém consulta. Uma sincronização por
            // família: configuração, estado e controlo são pacotes próprios.
            'sync_configuration',
            'time_format',
            'time_zone',
            // O trinco do prato é capacidade própria: destrancado é um estado sobre que se age.
            // Não é a «tampa», que é a leitura do tipo 01.
        ];

        self::assertSame(
            $expected,
            array_values(array_unique(array_map('strval', $pdo->query(
                "SELECT capability_key FROM capabilities WHERE device_type = 'pill_dispenser' ORDER BY capability_key"
            )->fetchAll(\PDO::FETCH_COLUMN))))
        );

        // Dezoito configuráveis e sete pedíveis, nunca as duas ao mesmo tempo; as leituras que o
        // `0x07` enche pede-as todas o `device_status`, que é a sétima pedível.
        self::assertSame(
            ['18', '7'],
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
            ['battery', 'connectivity', 'location', 'low_battery'],
            $db->modelCapabilities->enabledFeaturesForModelId((int)$mkgw4['id'])
        );
        self::assertSame(
            ['battery', 'change_required', 'check_required', 'diaper_condition', 'diaper_moisture', 'diaper_moisture_level', 'diaper_sensitivity', 'proximity'],
            $db->modelCapabilities->enabledFeaturesForModelId((int)$sensor['id'])
        );
    }

    /** A pulseira SOS Wi-Fi da PST só tem dois pontos de dados na Tuya: o botão e a bateria. */
    public function testTheWifiSosButtonHasOnlyTheButtonAndTheBattery(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());

        $button = $db->models->find('PST', 'CF103W');
        self::assertIsArray($button);
        self::assertSame('bracelet', $button['device_type']);
        self::assertSame(['battery', 'help_call'], $db->modelCapabilities->enabledFeaturesForModelId((int)$button['id']));
    }
}
