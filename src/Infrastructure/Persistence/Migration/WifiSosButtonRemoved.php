<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/** A pulseira SOS Wi-Fi não vai para produção: sai o fornecedor PST, com os modelos e os aparelhos dele. */
final class WifiSosButtonRemoved implements Migration
{
    public function version(): string
    {
        return '2026_10_30_wifi_sos_button_removed';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec("
            DELETE w FROM whitelist w
            JOIN models m ON m.id = w.model_id
            JOIN suppliers s ON s.id = m.supplier_id
            WHERE s.name = 'PST'
        ");
        $pdo->exec("DELETE m FROM models m JOIN suppliers s ON s.id = m.supplier_id WHERE s.name = 'PST'");
        $pdo->exec("DELETE FROM suppliers WHERE name = 'PST'");
    }
}
