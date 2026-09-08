<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * Passa o `monitor_number` de configuração a acção, na base.
 *
 * O `MONITOR` da 4P Touch não é uma definição: o relógio liga para o número no instante em que
 * recebe o comando, em escuta silenciosa, e não há forma de o gravar sem disparar a chamada.
 * Passou a acção pedida por `/requests`, e as linhas que ficaram descreviam uma definição que
 * o aparelho não tem -- a dashboard mostrava-as como aplicadas.
 *
 * A linha do catálogo vem junto: o `capabilities` só é semeado numa base vazia, e sem isto uma
 * base existente continuava a anunciar a capacidade como configurável em Contactos.
 *
 * O histórico em `device_configuration_changes` fica: os comandos saíram mesmo.
 */
final class DropMonitorNumberConfigurations implements Migration
{
    public function version(): string
    {
        return '2026_09_08_drop_monitor_number_configurations';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec("DELETE FROM device_configurations WHERE config_key = 'monitor_number'");
        $pdo->exec("
            UPDATE capabilities
            SET section = 'settings_system', is_configurable = 0, is_requestable = 1
            WHERE device_type = 'watch' AND capability_key = 'monitor_number'
        ");
    }
}
