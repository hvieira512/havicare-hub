<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use Hub\Domain\DeviceTypeCatalog;
use PDO;

/**
 * Dá aos tipos de dispositivo uma tabela e três chaves estrangeiras.
 *
 * O `device_type` era um `ENUM` declarado em `whitelist`, `models` e `capabilities`.
 * Acrescentar um tipo eram três `ALTER TABLE` que tinham de concordar, e a discordância não
 * dava erro: uma `whitelist` que aceitasse `bracelet` com um `capabilities` que não o
 * conhecesse deixava o dispositivo registado e sem capacidade nenhuma, calado. Passa a ser um
 * `INSERT` na `device_types`, que o semeador mantém igual ao `config/device-types.json`.
 *
 * A tabela em si vem do `schema.sql`, que corre antes das migrações. Aqui semeia-se, convertem-se
 * as três colunas, e ligam-se as chaves.
 *
 * **O custo, dito por inteiro:** duas das três tabelas não tinham índice que começasse pelo
 * `device_type`, e uma chave estrangeira precisa de um. São dois índices novos em tabelas
 * pequenas -- o preço da integridade que o `ENUM` não dava.
 */
final class DeviceTypesTable implements Migration
{
    /** A tabela, a definição da coluna, e o índice a criar se nenhum servir a chave. */
    private const TARGETS = [
        'whitelist' => ['idx_whitelist_device_type', true],
        'models' => ['idx_models_device_type', true],
        // A `uq_capabilities_device_type_key` já começa pelo `device_type`.
        'capabilities' => ['', false],
    ];

    public function version(): string
    {
        return '2026_09_04_device_types_table';
    }

    public function up(PDO $pdo): void
    {
        $insert = $pdo->prepare('INSERT IGNORE INTO device_types (device_type) VALUES (?)');
        foreach (DeviceTypeCatalog::keys() as $deviceType) {
            $insert->execute([$deviceType]);
        }

        foreach (self::TARGETS as $table => [$index, $needsIndex]) {
            if ($this->isEnum($pdo, $table)) {
                $this->assertEveryValueIsKnown($pdo, $table);
                $pdo->exec("ALTER TABLE {$table} MODIFY device_type VARCHAR(32) NOT NULL DEFAULT 'watch'");
            }

            if ($needsIndex && !$this->hasIndex($pdo, $table, $index)) {
                $pdo->exec("CREATE INDEX {$index} ON {$table} (device_type)");
            }

            $constraint = "fk_{$table}_device_type";
            // Uma chave estrangeira exige colação igual nos dois lados. Numa base que venha do
            // estado `ENUM`, o `schema.sql` já criou a `device_types` em `ascii_bin` e esta
            // coluna ainda está em `utf8mb4`: a chave fica para a migração da colação, que
            // converte as cinco e reconstrói as quatro.
            if (!$this->hasConstraint($pdo, $table, $constraint) && $this->collationsMatch($pdo, $table)) {
                $pdo->exec("
                    ALTER TABLE {$table}
                    ADD CONSTRAINT {$constraint} FOREIGN KEY (device_type)
                        REFERENCES device_types(device_type)
                ");
            }
        }
    }

    /** Um tipo em uso que a tabela não conheça faria a chave estrangeira recusar-se a nascer. */
    private function assertEveryValueIsKnown(PDO $pdo, string $table): void
    {
        $stmt = $pdo->query("
            SELECT COUNT(*) FROM {$table} t
            WHERE NOT EXISTS (SELECT 1 FROM device_types d WHERE d.device_type = t.device_type)
        ");
        $desconhecidos = (int)$stmt->fetchColumn();
        if ($desconhecidos > 0) {
            throw new \RuntimeException(
                "{$table} tem {$desconhecidos} linhas com um device_type que o "
                . 'config/device-types.json não declara'
            );
        }
    }

    private function isEnum(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('
            SELECT data_type FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
        ');
        $stmt->execute([$table, 'device_type']);

        return $stmt->fetchColumn() === 'enum';
    }

    private function collationsMatch(PDO $pdo, string $table): bool
    {
        return $this->collation($pdo, $table) === $this->collation($pdo, 'device_types');
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

    private function hasIndex(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?
        ');
        $stmt->execute([$table, $index]);

        return (int)$stmt->fetchColumn() > 0;
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
