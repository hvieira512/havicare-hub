<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * Põe as cinco colunas `device_type` em `ascii_bin`.
 *
 * Ao deixarem de ser `ENUM`, passaram a `VARCHAR(32)` em `utf8mb4_unicode_ci` -- a colação da
 * base. Para identificadores ASCII em minúsculas é a escolha mais lenta que havia: a UCA pesa
 * cada carácter em vários níveis, onde um `memcmp` bastava, e o `key_len` declarado do índice
 * fica em 130 bytes por coluna.
 *
 * Medido num meio milhão de linhas, com a mesma chave primária e o mesmo índice da produção:
 * um `IN` de três tipos custa 28,7 ms em `ascii_bin` contra 42,9 em `utf8mb4_unicode_ci`, e um
 * agrupamento 68,0 contra 80,2. A busca indexada exacta não muda -- 0,18 ms nas duas --, o que
 * confirma que os 130 bytes eram comprimento declarado e não bytes lidos. O `key_len` do
 * `idx_whitelist_license_device_type` cai de 135 para 39.
 *
 * **Muda um comportamento:** `ascii_bin` distingue maiúsculas. Com a colação anterior, um
 * `Watch` casava com o `watch` do catálogo e entrava; agora a chave estrangeira recusa-o. É o
 * lado certo -- os dez caminhos de escrita passam pelo `DeviceMetadata::normalizeDeviceType()`,
 * que faz `strtolower`, pelo que nada de legítimo chega em maiúsculas, e as quatro tabelas de
 * produção não tinham um único valor fora de minúsculas.
 *
 * As chaves estrangeiras exigem colação igual nos dois lados, e por isso saem antes e voltam
 * depois -- não há como alterar as colunas com elas no lugar.
 */
final class DeviceTypeAsciiCollation implements Migration
{
    private const DEFINITION = 'VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL';

    /** tabela => definição da coluna a seguir ao tipo */
    private const COLUMNS = [
        'device_types' => '',
        'whitelist' => " DEFAULT 'watch'",
        'models' => " DEFAULT 'watch'",
        'capabilities' => " DEFAULT 'watch'",
        'model_capabilities' => '',
    ];

    /** As chaves que assentam nestas colunas, e como se reconstroem. */
    private const CONSTRAINTS = [
        'whitelist' => [
            'fk_whitelist_device_type',
            'FOREIGN KEY (device_type) REFERENCES device_types(device_type)',
        ],
        'models' => [
            'fk_models_device_type',
            'FOREIGN KEY (device_type) REFERENCES device_types(device_type)',
        ],
        'capabilities' => [
            'fk_capabilities_device_type',
            'FOREIGN KEY (device_type) REFERENCES device_types(device_type)',
        ],
        'model_capabilities' => [
            'fk_model_capabilities_capability_v3',
            'FOREIGN KEY (device_type, capability_key) '
                . 'REFERENCES capabilities(device_type, capability_key) '
                . 'ON DELETE CASCADE ON UPDATE CASCADE',
        ],
    ];

    public function version(): string
    {
        return '2026_09_04_device_type_ascii_collation';
    }

    public function up(PDO $pdo): void
    {
        if ($this->collation($pdo, 'device_types') === 'ascii_bin') {
            return;
        }

        $this->assertEverythingIsLowercase($pdo);

        foreach (self::CONSTRAINTS as $table => [$name]) {
            if ($this->hasConstraint($pdo, $table, $name)) {
                $pdo->exec("ALTER TABLE {$table} DROP FOREIGN KEY {$name}");
            }
        }

        foreach (self::COLUMNS as $table => $extra) {
            $pdo->exec("ALTER TABLE {$table} MODIFY device_type " . self::DEFINITION . $extra);
        }

        foreach (self::CONSTRAINTS as $table => [$name, $definition]) {
            if (!$this->hasConstraint($pdo, $table, $name)) {
                $pdo->exec("ALTER TABLE {$table} ADD CONSTRAINT {$name} {$definition}");
            }
        }
    }

    /**
     * Um valor em maiúsculas sobreviveria à alteração da coluna e só depois faria a chave
     * estrangeira recusar-se a nascer, deixando a base a meio. Vale mais parar antes.
     */
    private function assertEverythingIsLowercase(PDO $pdo): void
    {
        foreach (array_keys(self::COLUMNS) as $table) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM {$table} WHERE device_type <> LOWER(device_type)");
            $maiusculas = (int)$stmt->fetchColumn();
            if ($maiusculas > 0) {
                throw new \RuntimeException(
                    "{$table} tem {$maiusculas} linhas com device_type fora de minúsculas; "
                    . 'em ascii_bin deixariam de casar com o catálogo'
                );
            }
        }
    }

    private function collation(PDO $pdo, string $table): string
    {
        $stmt = $pdo->prepare('
            SELECT collation_name FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
        ');
        $stmt->execute([$table, 'device_type']);

        return (string)$stmt->fetchColumn();
    }

    private function hasConstraint(PDO $pdo, string $table, string $constraint): bool
    {
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM information_schema.table_constraints
            WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = ?
        ');
        $stmt->execute([$table, $constraint]);

        return (int)$stmt->fetchColumn() > 0;
    }
}
