<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * A fotografia da pulseira SOS Wi-Fi da PST, nas bases onde o modelo ficou sem nenhuma. O ficheiro
 * vem de `database/seed-model-images`, copiado pelo migrador.
 */
final class WifiSosButtonImage implements Migration
{
    public function version(): string
    {
        return '2026_10_14_wifi_sos_button_image';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec("
            UPDATE models m JOIN suppliers s ON s.id = m.supplier_id
            SET m.image_path = '35256203f0a245d0440995dea4b84177.jpg'
            WHERE s.name = 'PST' AND m.internal_model = 'CF103W' AND m.image_path = ''
        ");
    }
}
