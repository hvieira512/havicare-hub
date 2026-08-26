<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * Deixa cair a `diaper_sensor_settings`.
 *
 * A tabela existia porque a sensibilidade dos alertas não era uma capacidade e não tinha
 * por onde entrar no ciclo de vida das configurações. Agora é, e os valores vivem na
 * `device_configurations` com todos os outros -- copiados pela migração anterior, que é
 * quem tem de correr primeiro.
 *
 * Separada da cópia de propósito: uma migração que apaga dados não deve ser a mesma que os
 * move, para que o `up` da cópia possa ser lido, corrido e verificado sem a rede por baixo
 * a desaparecer no mesmo passo.
 */
final class Version2026082802DropDiaperSensorSettings implements Migration
{
    public function version(): string
    {
        return '2026082802_drop_diaper_sensor_settings';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS diaper_sensor_settings');
    }
}
