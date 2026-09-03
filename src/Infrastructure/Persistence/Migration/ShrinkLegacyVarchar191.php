<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * Encurta as colunas que estavam em `VARCHAR(191)`.
 *
 * O 191 vem do limite de 767 bytes por índice do InnoDB em utf8mb3, no MySQL 5.5: 767 ÷ 4.
 * Aqui o motor é MariaDB 10.11 com `ROW_FORMAT=DYNAMIC`, onde o limite é 3 072 -- o contorno
 * sobreviveu ao problema que o justificava.
 *
 * As chaves técnicas ficam em 64 e os nomes visíveis em 96, com folga sobre o que está em uso
 * (o mais longo tinha 50 caracteres). Onde se paga é na chave primária da
 * `device_configurations`, que declarava 1 790 bytes para valores que somados nunca passam de
 * 79 caracteres.
 *
 * Cada coluna é medida antes de ser encurtada: se algum valor não couber, a migração desiste
 * em vez de o truncar.
 */
final class ShrinkLegacyVarchar191 implements Migration
{
    /** tabela => [coluna => [largura, definição a seguir ao tipo]] */
    private const COLUMNS = [
        'schema_migrations' => ['version' => [96, 'NOT NULL']],
        'suppliers' => ['name' => [96, 'NOT NULL']],
        'models' => [
            'internal_model' => [96, 'NOT NULL'],
            'commercial_name' => [96, 'NOT NULL'],
        ],
        'capabilities' => [
            'capability_key' => [64, 'NOT NULL'],
            'label' => [96, 'NOT NULL'],
        ],
        'whitelist' => [
            'supplier' => [96, 'NOT NULL'],
            'model' => [96, 'NOT NULL'],
            'device_id' => [96, "NOT NULL DEFAULT ''"],
            'company' => [96, 'NULL DEFAULT NULL'],
        ],
        'device_configurations' => [
            'config_key' => [64, 'NOT NULL'],
            'native_key' => [64, 'NOT NULL'],
            'command' => [64, "NOT NULL DEFAULT ''"],
        ],
        'device_configuration_changes' => ['config_key' => [64, 'NOT NULL']],
        'device_configuration_operations' => [
            'native_key' => [64, 'NOT NULL'],
            'native_type' => [64, 'NOT NULL'],
        ],
        'companies' => ['name' => [96, 'NOT NULL']],
        'licenses' => ['name' => [96, 'NOT NULL']],
        'api_users' => ['username' => [96, 'NOT NULL']],
        'dashboard_notifications' => [
            'model' => [96, "NOT NULL DEFAULT ''"],
            'ident' => [96, "NOT NULL DEFAULT ''"],
            'company' => [96, 'NULL DEFAULT NULL'],
            'reason' => [96, "NOT NULL DEFAULT ''"],
        ],
    ];

    public function version(): string
    {
        return '2026_09_04_shrink_legacy_varchar_191';
    }

    public function up(PDO $pdo): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column => [$width, $definition]) {
                if ($this->currentWidth($pdo, $table, $column) <= $width) {
                    continue;
                }

                $this->assertEverythingFits($pdo, $table, $column, $width);
                $pdo->exec("ALTER TABLE {$table} MODIFY {$column} VARCHAR({$width}) {$definition}");
            }
        }
    }

    private function assertEverythingFits(PDO $pdo, string $table, string $column, int $width): void
    {
        $stmt = $pdo->query("SELECT COUNT(*) FROM {$table} WHERE CHAR_LENGTH({$column}) > {$width}");
        $grandes = (int)$stmt->fetchColumn();
        if ($grandes > 0) {
            throw new \RuntimeException(
                "{$table}.{$column} tem {$grandes} valores com mais de {$width} caracteres; "
                . 'encurtar a coluna truncava-os'
            );
        }
    }

    private function currentWidth(PDO $pdo, string $table, string $column): int
    {
        $stmt = $pdo->prepare('
            SELECT character_maximum_length FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
        ');
        $stmt->execute([$table, $column]);
        $width = $stmt->fetchColumn();

        return $width === false || $width === null ? 0 : (int)$width;
    }
}
