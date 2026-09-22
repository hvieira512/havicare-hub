<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * Tira do catálogo do dispensador a acção que prometia desligar a cifra.
 *
 * O M228 cifra em AES128-CFB o corpo de tudo o que envia por iniciativa própria — o heartbeat,
 * as notificações, e com eles o evento de toma de medicação. O `0x8005` aparece na
 * especificação entre os parâmetros de configuração, e daí saiu a leitura de que bastava
 * escrever-lhe zero para a desligar sem precisar da chave.
 *
 * O fornecedor respondeu que não: «0x8005 cannot be set», e que tanto a chave como os números
 * aleatórios são derivados da codificação do próprio aparelho — ou ele cifra tudo o que envia,
 * ou não cifra nada, e não é deste lado que isso se escolhe. A acção ficava a ser um botão que
 * o firmware recusa sempre, o que é pior do que botão nenhum: promete uma saída que não existe.
 *
 * Esta migração desfaz a inserção que a versão `2026_09_21_pill_dispenser_encryption_switch`
 * fez nas bases que já a correram.
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
