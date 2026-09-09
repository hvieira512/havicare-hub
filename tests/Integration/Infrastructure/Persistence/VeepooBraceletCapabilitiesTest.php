<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Migration\VeepooBraceletCapabilities;
use Tests\Support\MysqlDashboardTestCase;

/**
 * Esta migração tira duas chaves às pulseiras: a deteção de queda, que a MF91 aceita e não
 * tem sensor para cumprir, e o `blood_oxygen_continuous`, que era um nome errado.
 *
 * As duas existem também nos relógios, e aí são reais -- a 4P Touch e a Vivistar declaram-nas
 * as duas. O `device_configurations` é indexado por IMEI e chave, sem coluna de tipo, e por
 * isso apagar por chave sem dizer de que aparelhos apagava a configuração guardada de todos.
 * Medido em produção antes de correr: catorze relógios com `fall_detection` e dois com
 * `blood_oxygen_continuous`.
 */
final class VeepooBraceletCapabilitiesTest extends MysqlDashboardTestCase
{
    public function testItForgetsTheBraceletKeysWithoutTouchingTheWatches(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $pdo->exec("DELETE FROM device_configurations");

        $register = $pdo->prepare(
            'INSERT INTO whitelist (imei, supplier, model, device_type) VALUES (?, ?, ?, ?)'
        );
        $register->execute(['860000000000001', 'Wonlex', 'MF91', 'bracelet']);
        $register->execute(['860000000000002', '4P Touch', 'Y6M', 'watch']);

        $configure = $pdo->prepare(
            'INSERT INTO device_configurations (imei, config_key, native_key, protocol, desired_payload, reported_payload)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach (['fall_detection', 'blood_oxygen_continuous'] as $key) {
            $configure->execute(['860000000000001', $key, $key, 'veepoo', '{}', '{}']);
            $configure->execute(['860000000000002', $key, $key, 'four-p-touch', '{}', '{}']);
        }

        (new VeepooBraceletCapabilities())->up($pdo);

        $remaining = $pdo->query(
            "SELECT imei FROM device_configurations
              WHERE config_key IN ('fall_detection', 'blood_oxygen_continuous')
              ORDER BY imei"
        )->fetchAll(\PDO::FETCH_COLUMN);

        self::assertSame(
            ['860000000000002', '860000000000002'],
            $remaining,
            'a configuração dos relógios não é desta migração'
        );
    }
}
