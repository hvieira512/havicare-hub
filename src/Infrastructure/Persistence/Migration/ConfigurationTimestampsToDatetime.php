<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * Converte os onze instantes das tabelas de configuração de `VARCHAR(32)` para `DATETIME`.
 *
 * Guardavam ISO-8601 em texto -- `2026-07-30T12:07:23Z` --, o que funcionava porque esse
 * formato ordena lexicograficamente, e usavam a cadeia vazia para "ainda não". Era o segundo
 * vocabulário para a ausência neste esquema: as tabelas de catálogo, ao lado, sempre usaram
 * `NULL`. É isso que fazia o `WHERE superseded_at = ''` significar "esta é a corrente", e é
 * `IS NULL` a partir daqui.
 *
 * A conversão faz-se em SQL, com `STR_TO_DATE` sobre o formato único que lá está e `NULLIF`
 * para as vazias. Uma coluna só é convertida se todos os valores não vazios forem
 * reconhecíveis: caso contrário desiste, porque um `ALTER` sobre um valor que o MariaDB não
 * entenda grava zeros em silêncio.
 */
final class ConfigurationTimestampsToDatetime implements Migration
{
    /** As colunas por tabela, e se aceitam ausência. */
    private const COLUMNS = [
        'device_configurations' => [
            'desired_updated_at' => true,
            'reported_at' => true,
            'applied_at' => true,
        ],
        'device_configuration_changes' => [
            'created_at' => false,
            'updated_at' => false,
            'confirmed_at' => true,
            'superseded_at' => true,
        ],
        'device_configuration_operations' => [
            'created_at' => false,
            'updated_at' => false,
            'sent_at' => true,
            'acknowledged_at' => true,
        ],
    ];

    private const ISO = '%Y-%m-%dT%H:%i:%sZ';

    public function version(): string
    {
        return '2026_09_04_configuration_timestamps_to_datetime';
    }

    public function up(PDO $pdo): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column => $nullable) {
                if (!$this->isVarchar($pdo, $table, $column)) {
                    continue;
                }

                $this->assertEveryValueParses($pdo, $table, $column);

                // Primeiro o conteúdo, ainda como texto: o ISO passa ao formato que o `ALTER`
                // seguinte lê sem ambiguidade.
                $pdo->exec("
                    UPDATE {$table}
                    SET {$column} = DATE_FORMAT(STR_TO_DATE({$column}, '" . self::ISO . "'), '%Y-%m-%d %H:%i:%s')
                    WHERE {$column} IS NOT NULL AND {$column} <> ''
                ");

                // A coluna só aceita NULL depois de deixar de ser NOT NULL, e é por isso que
                // este passo vem antes de trocar o tipo -- não depois.
                if ($nullable) {
                    $pdo->exec("ALTER TABLE {$table} MODIFY {$column} VARCHAR(32) NULL DEFAULT NULL");
                    $pdo->exec("UPDATE {$table} SET {$column} = NULL WHERE {$column} = ''");
                }

                $definition = $nullable ? 'DATETIME NULL DEFAULT NULL' : 'DATETIME NOT NULL';
                $pdo->exec("ALTER TABLE {$table} MODIFY {$column} {$definition}");
            }
        }
    }

    /**
     * Uma linha cujo texto o `STR_TO_DATE` não reconheça sairia da conversão a `NULL`, e numa
     * coluna `NOT NULL` sairia a zeros. Vale mais parar a migração.
     */
    private function assertEveryValueParses(PDO $pdo, string $table, string $column): void
    {
        $stmt = $pdo->query("
            SELECT COUNT(*) FROM {$table}
            WHERE {$column} IS NOT NULL AND {$column} <> ''
              AND STR_TO_DATE({$column}, '" . self::ISO . "') IS NULL
        ");
        $ilegiveis = (int)$stmt->fetchColumn();
        if ($ilegiveis > 0) {
            throw new \RuntimeException(
                "{$table}.{$column} tem {$ilegiveis} valores que não são ISO-8601; "
                . 'a conversão para DATETIME perdê-los-ia'
            );
        }
    }

    private function isVarchar(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare('
            SELECT data_type FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
        ');
        $stmt->execute([$table, $column]);

        return $stmt->fetchColumn() === 'varchar';
    }
}
