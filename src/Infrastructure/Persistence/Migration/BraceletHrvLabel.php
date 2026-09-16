<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * A VFC da pulseira passa a chamar-se como a do relógio.
 *
 * A mesma grandeza tinha dois nomes conforme o aparelho -- `VFC` no relógio, `HRV` na
 * pulseira --, e a etiqueta é o que aparece no ecrã e sai na API. O nome está em português no
 * resto do catálogo.
 */
final class BraceletHrvLabel implements Migration
{
    public function version(): string
    {
        return '2026_09_16_bracelet_hrv_label';
    }

    public function up(PDO $pdo): void
    {
        // Numa base ainda por semear não há nada a acertar: o seeder escreve o catálogo já
        // corrigido, a partir das mesmas definições em código.
        if ((int)$pdo->query('SELECT COUNT(*) FROM capabilities')->fetchColumn() === 0) {
            return;
        }

        $pdo->exec("
            UPDATE capabilities SET label = 'VFC'
            WHERE device_type = 'bracelet' AND capability_key = 'hrv' AND label = 'HRV'
        ");
    }
}
