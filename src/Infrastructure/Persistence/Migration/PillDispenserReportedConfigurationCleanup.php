<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * Tira as linhas de configuração que o caminho antigo deixou no dispensador.
 *
 * Antes de a leitura do `0x05` ser guardada por chave, a projeção resolvia-a a partir do tipo
 * da resposta, e nasciam linhas `read_config_ack` e `write_config_ack` que não são
 * capacidades nenhumas. Só as do dispensador, e só essas duas chaves.
 */
final class PillDispenserReportedConfigurationCleanup implements Migration
{
    public function version(): string
    {
        return '2026_09_22_pill_dispenser_reported_configuration_cleanup';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec("
            DELETE FROM device_configurations
            WHERE protocol = 'zayata-m228'
              AND config_key IN ('read_config_ack', 'write_config_ack')
        ");
    }
}
