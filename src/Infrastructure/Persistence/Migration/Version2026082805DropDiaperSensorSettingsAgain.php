<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * Larga a `diaper_sensor_settings` nas bases que já existem, onde o `schema.sql` a criou
 * antes de deixar de a declarar.
 *
 * O `DatabaseMigrator` executa o `schema.sql` antes das migrações e em todas as invocações,
 * e por isso uma migração que larga algo que o `schema.sql` continua a declarar é desfeita
 * na execução seguinte -- é o `SchemaCompletenessTest` que agora prova que os dois descrevem
 * a mesma base.
 */
final class Version2026082805DropDiaperSensorSettingsAgain implements Migration
{
    public function version(): string
    {
        return '2026082805_drop_diaper_sensor_settings_again';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS diaper_sensor_settings');
    }
}
