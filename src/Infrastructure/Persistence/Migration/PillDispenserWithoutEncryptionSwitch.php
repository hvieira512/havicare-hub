<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * Tira do catálogo do dispensador a acção que prometia desligar a cifra.
 *
 * O `0x8005` aparece na especificação entre os parâmetros de configuração, mas o fornecedor
 * confirmou que não se escreve: ou o aparelho cifra tudo o que envia, ou não cifra nada, e
 * não é deste lado que isso se escolhe. Desfaz a inserção que a versão
 * `2026_09_21_pill_dispenser_encryption_switch` fez nas bases que já a correram.
 */
final class PillDispenserWithoutEncryptionSwitch implements Migration
{
    public function version(): string
    {
        return '2026_09_22_pill_dispenser_without_encryption_switch';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec("
            DELETE FROM model_capabilities
            WHERE device_type = 'pill_dispenser' AND capability_key = 'disable_encryption'
        ");
        $pdo->exec("
            DELETE FROM capabilities
            WHERE device_type = 'pill_dispenser' AND capability_key = 'disable_encryption'
        ");
    }
}
