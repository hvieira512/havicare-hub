<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * O sinal do dispensador passa a ser a `connectivity` que o hub já tem, e o ambiente vira
 * alerta.
 *
 * Duas coisas que estavam feitas só para este aparelho e não precisavam de estar. A ligação à
 * rede já tem forma no contrato — os gateways publicam `interface` e `signalStrengthDbm`, e a
 * dashboard já os desenha —, e publicar `gsmSignalDbm` dentro de um `device_status` obrigava
 * quem integra a conhecer mais um formato para ler a mesma grandeza. O `device_status` fica
 * como o que sempre foi na prática: o botão que pede ao aparelho o estado que ele tem agora.
 *
 * E o ambiente de armazenamento sai da telemetria para os alarmes. Publicado a cada leitura,
 * enchia a lista de eventos com linhas iguais a dizer «Dentro da gama» — o estado normal, que
 * ninguém lê. Passa a falar só quando a temperatura ou a humidade saem da gama, como a avaria
 * já fazia, e o nome diz o que aconteceu em vez de nomear o sensor.
 */
final class PillDispenserGenericConnectivity implements Migration
{
    public function version(): string
    {
        return '2026_09_23_pill_dispenser_generic_connectivity';
    }

    public function up(PDO $pdo): void
    {
        if ((int)$pdo->query('SELECT COUNT(*) FROM capabilities')->fetchColumn() === 0) {
            return;
        }

        $pdo->exec("
            INSERT INTO capabilities (device_type, section, capability_key, label, is_configurable, is_requestable)
            VALUES ('pill_dispenser', 'telemetry', 'connectivity', 'Conectividade', 0, 0)
            ON DUPLICATE KEY UPDATE section = VALUES(section), label = VALUES(label)
        ");
        $pdo->exec("
            INSERT IGNORE INTO model_capabilities (model_id, device_type, capability_key, enabled)
            SELECT m.id, 'pill_dispenser', 'connectivity', 1
            FROM models m
            JOIN suppliers s ON s.id = m.supplier_id
            WHERE s.name = 'Zayata' AND m.internal_model = 'M228'
        ");

        $pdo->exec("
            UPDATE capabilities
            SET section = 'alarms', label = 'Medicação mal conservada'
            WHERE device_type = 'pill_dispenser' AND capability_key = 'storage_environment'
        ");
    }
}
