<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * Deixa cair a `diaper_sensor_settings`.
 *
 * A tabela existia porque a sensibilidade dos alertas não era uma capacidade e não tinha
 * por onde entrar no ciclo de vida das configurações. Agora é, e os valores vivem na
 * `device_configurations` com todos os outros.
 *
 * Sem cópia: produção tem um sensor, no preset normal, e a ausência de linha já significa
 * normal. Fica separada da migração que declara a capacidade na mesma, porque são duas
 * coisas -- uma acrescenta, a outra apaga -- e ler o `up` de cada uma diz-se de uma vez.
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
