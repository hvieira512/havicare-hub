<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * Larga a `diaper_sensor_settings` outra vez.
 *
 * A `2026082802` já a largou e correu com sucesso. O que a trouxe de volta foi o
 * `DatabaseMigrator`: ele executa o `database/schema.sql` antes das migrações, em todas as
 * invocações, e o `schema.sql` continuava a declarar a tabela. Cada `bin/migrate.php`
 * recriava-a, e a migração que a apagava estava registada e não voltava a correr.
 *
 * O `schema.sql` já não a declara, mas nas bases que existem hoje -- local e produção -- a
 * tabela está lá e nada a apaga. Daí esta, que é a segunda metade do arranjo.
 *
 * A classe de defeito não é sobre fraldas: uma migração que larga algo que o `schema.sql`
 * continua a declarar é desfeita na execução seguinte. O
 * `SchemaCompletenessTest` passou a provar que os dois descrevem a mesma base.
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
