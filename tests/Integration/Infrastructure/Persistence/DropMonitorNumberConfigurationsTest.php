<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Migration\DropMonitorNumberConfigurations;
use PDO;
use Tests\Support\MysqlDashboardTestCase;

/**
 * A limpeza que esta migração faz às linhas que já existem.
 *
 * O `monitor_number` deixou de ser configuração: o `MONITOR` põe o relógio a ligar no instante
 * em que o recebe, e não há forma de gravar o número sem disparar a chamada. As linhas que
 * ficaram em `device_configurations` descrevem uma definição que o aparelho não tem, e a
 * dashboard mostrava-as como aplicadas.
 *
 * Retira-se com a migração, quando ela sair do plano.
 */
final class DropMonitorNumberConfigurationsTest extends MysqlDashboardTestCase
{
    private function insertConfiguration(PDO $pdo, string $imei, string $configKey, string $nativeKey): void
    {
        $pdo->prepare('
            INSERT INTO device_configurations (
                imei, config_key, native_key, protocol, command,
                desired_payload, reported_payload, last_status, applied_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            $imei, $configKey, $nativeKey, 'four-p-touch', strtoupper($nativeKey),
            '{"phone":"+351938854803"}', '{}', 'confirmed', '2026-08-03 14:40:21',
        ]);
    }

    /** @return list<string> */
    private function configKeys(PDO $pdo, string $imei): array
    {
        $stmt = $pdo->prepare('SELECT config_key FROM device_configurations WHERE imei = ? ORDER BY config_key');
        $stmt->execute([$imei]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function testTheMonitorNumberRowGoesAndTheOtherContactsStay(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $this->insertConfiguration($pdo, '351266770073676', 'monitor_number', 'monitorNumber');
        $this->insertConfiguration($pdo, '351266770073676', 'center_number', 'centerNumber');
        $this->insertConfiguration($pdo, '861728087056333', 'monitor_number', 'monitorNumber');

        (new DropMonitorNumberConfigurations())->up($pdo);

        self::assertSame(['center_number'], $this->configKeys($pdo, '351266770073676'));
        self::assertSame([], $this->configKeys($pdo, '861728087056333'));
    }

    public function testTheCapabilityBecomesARequestableActionOutsideContacts(): void
    {
        // O catálogo só é semeado numa base vazia; numa base existente é a migração que o
        // faz evoluir, senão a dashboard continua a mostrar o campo em Contactos.
        $pdo = $this->createDashboardDatabase()->pdo();
        $pdo->exec("
            UPDATE capabilities
            SET section = 'contacts', is_configurable = 1, is_requestable = 0
            WHERE device_type = 'watch' AND capability_key = 'monitor_number'
        ");

        (new DropMonitorNumberConfigurations())->up($pdo);

        $row = $pdo
            ->query("
                SELECT section, is_configurable, is_requestable FROM capabilities
                WHERE device_type = 'watch' AND capability_key = 'monitor_number'
            ")
            ->fetch(PDO::FETCH_ASSOC);
        self::assertSame('settings_system', $row['section']);
        self::assertSame(0, (int)$row['is_configurable']);
        self::assertSame(1, (int)$row['is_requestable']);
    }

    public function testRunningItOnABaseWithoutMonitorRowsDoesNothing(): void
    {
        // A linha deste relógio já foi apagada à mão em produção antes de existir a migração.
        $pdo = $this->createDashboardDatabase()->pdo();
        $this->insertConfiguration($pdo, '351266770073676', 'center_number', 'centerNumber');

        (new DropMonitorNumberConfigurations())->up($pdo);

        self::assertSame(['center_number'], $this->configKeys($pdo, '351266770073676'));
    }
}
