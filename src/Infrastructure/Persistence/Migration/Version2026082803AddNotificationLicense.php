<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * A notificação de um dispositivo não autorizado ganha a licença.
 *
 * Nem todos os protocolos sabem a licença de um dispositivo que ainda não está registado.
 * O radar sabe: publica em `radar/{licenca}/{uid}`. Os outros identificam-se só por MAC ou
 * por endereço, e para esses fica a zero.
 *
 * É um campo e não texto dentro do `ident` porque a dashboard usa-o para pré-seleccionar a
 * licença no assistente de registo, tal como já pré-selecciona o tipo e o modelo a partir
 * do protocolo. Um número escolhe-se numa lista; uma frase teria de ser interpretada.
 *
 * Fora da chave única `(type, imei, protocol)` de propósito: se o mesmo dispositivo
 * aparecer noutra licença, é a mesma notificação com o valor actualizado, e não uma linha
 * nova.
 */
final class Version2026082803AddNotificationLicense implements Migration
{
    public function version(): string
    {
        return '2026082803_add_notification_license';
    }

    public function up(PDO $pdo): void
    {
        $column = $pdo->query("SHOW COLUMNS FROM dashboard_notifications LIKE 'license_id'");
        if ($column !== false && $column->fetch() !== false) {
            return;
        }

        $pdo->exec('
            ALTER TABLE dashboard_notifications
            ADD COLUMN license_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER ident
        ');
    }
}
