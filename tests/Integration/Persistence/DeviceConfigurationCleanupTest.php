<?php

declare(strict_types=1);

namespace Tests\Integration\Persistence;

use Hub\Api\Repository\WhitelistRepository;
use PDO;
use Tests\Support\MysqlDashboardTestCase;

/**
 * As configurações de um dispositivo desaparecem com ele.
 *
 * Sem isto, as linhas do ciclo de vida das configurações ficam para trás e um IMEI registado
 * outra vez herda os valores desejados do dono anterior.
 *
 * O teste passa pelo `unregister`, que é por onde um dispositivo sai do registo, e não por
 * um `DELETE` à mão: o que se garante é o comportamento do caminho, não a existência de
 * uma chave estrangeira -- que aqui seria um risco, porque a ingestão escreve
 * configurações reportadas sem passar pela whitelist.
 */
final class DeviceConfigurationCleanupTest extends MysqlDashboardTestCase
{
    private const IMEI = '861265061009822';

    private function pdoWithConfiguredDevice(): PDO
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $pdo->prepare('
            INSERT INTO whitelist (imei, supplier, model, device_type, license_id, company)
            VALUES (?, ?, ?, ?, ?, ?)
        ')->execute([self::IMEI, 'Vivistar', 'L08 PRO', 'watch', 1001, 'hitcare']);

        $pdo->prepare('
            INSERT INTO device_configuration_changes (
                change_id, imei, config_key, desired_revision, desired_payload,
                sync_status, created_at, updated_at
            ) VALUES (?, ?, ?, 1, ?, ?, ?, ?)
        ')->execute(['change-1', self::IMEI, 'alarm_clock', '{}', 'confirmed', '', '']);

        $pdo->prepare('
            INSERT INTO device_configuration_operations (
                operation_id, change_id, imei, config_key, native_key, native_type,
                protocol, confirmation_mode,
                delivery_status, created_at, updated_at, sequence_number
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
        ')->execute([
            'operation-1', 'change-1', self::IMEI, 'alarm_clock', 'REMIND', 'REMIND',
            'vivistar-iw', 'execution_ack', 'acked', '', '',
        ]);

        $pdo->prepare('
            INSERT INTO device_configurations (
                imei, config_key, native_key, protocol, desired_payload, reported_payload
            ) VALUES (?, ?, ?, ?, ?, ?)
        ')->execute([self::IMEI, 'alarm_clock', 'REMIND', 'vivistar-iw', '{}', '{}']);

        return $pdo;
    }

    /** @return array<string, int> */
    private function rowCounts(PDO $pdo): array
    {
        $counts = [];
        foreach (
            [
            'device_configurations',
            'device_configuration_changes',
            'device_configuration_operations',
            ] as $table
        ) {
            $statement = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE imei = ?");
            $statement->execute([self::IMEI]);
            $counts[$table] = (int)$statement->fetchColumn();
        }

        return $counts;
    }

    public function testDeletingADeviceTakesItsConfigurationsWithIt(): void
    {
        $pdo = $this->pdoWithConfiguredDevice();

        self::assertSame([
            'device_configurations' => 1,
            'device_configuration_changes' => 1,
            'device_configuration_operations' => 1,
        ], $this->rowCounts($pdo));

        (new WhitelistRepository($pdo))->unregister(self::IMEI);

        // As operações não são apagadas por nome: cascateiam a partir das alterações, que é
        // o mesmo resultado por um caminho a menos.
        self::assertSame([
            'device_configurations' => 0,
            'device_configuration_changes' => 0,
            'device_configuration_operations' => 0,
        ], $this->rowCounts($pdo));
    }

    public function testAConfigurationCanBeStoredForADeviceTheHubHasNotRegistered(): void
    {
        // O `DeviceEventStore` grava a configuração reportada por um `device_config` direto
        // do caminho de ingestão, sem passar pela whitelist. Uma chave estrangeira tornava
        // isto numa excepção no caminho quente, e é por isso que a limpeza é explícita.
        $pdo = $this->createDashboardDatabase()->pdo();

        $pdo->prepare('
            INSERT INTO device_configurations (
                imei, config_key, native_key, protocol, desired_payload, reported_payload
            ) VALUES (?, ?, ?, ?, ?, ?)
        ')->execute(['861265069999999', 'alarm_clock', 'REMIND', 'vivistar-iw', '{}', '{}']);

        $statement = $pdo->prepare('SELECT COUNT(*) FROM device_configurations WHERE imei = ?');
        $statement->execute(['861265069999999']);
        self::assertSame(1, (int)$statement->fetchColumn());
    }
}
