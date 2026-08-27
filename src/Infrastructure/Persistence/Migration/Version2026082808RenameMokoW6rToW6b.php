<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * A pulseira chama-se W6B, não W6R.
 *
 * O nome errado entrou no catálogo, nos dispositivos registados e no identificador de
 * protocolo, e o código foi todo renomeado com ele. Isto acerta os dados que já existem;
 * uma base nova nasce logo certa a partir do seeder.
 *
 * O histórico já gravado -- telemetria e eventos guardados com `source.protocol` a dizer
 * `moko-w6r` -- fica como está. Foi gravado com o nome que o hub usava na altura, e reescrevê-lo
 * seria inventar um passado. O estado em Redis reescreve-se sozinho no avistamento seguinte.
 */
final class Version2026082808RenameMokoW6rToW6b implements Migration
{
    public function version(): string
    {
        return '2026082808_rename_moko_w6r_to_w6b';
    }

    public function up(PDO $pdo): void
    {
        $pdo->prepare('
            UPDATE models m
            JOIN suppliers s ON s.id = m.supplier_id
            SET m.internal_model = ?, m.commercial_name = ?
            WHERE s.name = ? AND m.internal_model = ?
        ')->execute(['W6B', 'MOKO W6B', 'MOKO', 'W6R']);

        $pdo->prepare('UPDATE whitelist SET model = ? WHERE supplier = ? AND model = ?')
            ->execute(['W6B', 'MOKO', 'W6R']);

        foreach (['device_configurations', 'dashboard_notifications'] as $table) {
            $pdo->prepare("UPDATE {$table} SET model = ? WHERE model = ?")
                ->execute(['W6B', 'W6R']);
            $pdo->prepare("UPDATE {$table} SET protocol = ? WHERE protocol = ?")
                ->execute(['moko-w6b', 'moko-w6r']);
        }

        $pdo->prepare('UPDATE device_configuration_operations SET protocol = ? WHERE protocol = ?')
            ->execute(['moko-w6b', 'moko-w6r']);
    }
}
