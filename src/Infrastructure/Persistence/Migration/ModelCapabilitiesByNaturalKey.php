<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * Aponta a `model_capabilities` ao par natural da capacidade, em vez do `capabilities.id`.
 *
 * O id não era falado por ninguém: todas as leituras juntavam a `capabilities` só para o
 * traduzir de volta à chave, e todas as escritas faziam a tradução inversa antes. Renomear
 * uma capacidade obrigava a preservar o id à mão, ou as ligações por modelo desapareciam por
 * cascata -- agora é o `ON UPDATE CASCADE` que as leva atrás.
 *
 * O `capabilities.id` fica onde estava: é o identificador que a API expõe.
 *
 * **A troca, dita por inteiro:** a linha filha passa a guardar o `device_type`, que o modelo
 * também tem. É uma repetição -- mas nada garantia, antes, que o `capability_id` apontasse
 * para uma capacidade do tipo do modelo; a diferença é que agora fica à vista em vez de
 * escondida atrás de um número.
 */
final class ModelCapabilitiesByNaturalKey implements Migration
{
    public function version(): string
    {
        return '2026_09_04_model_capabilities_by_natural_key';
    }

    public function up(PDO $pdo): void
    {
        if (!$this->hasColumn($pdo, 'capability_id')) {
            return;
        }

        if (!$this->hasColumn($pdo, 'capability_key')) {
            $pdo->exec("ALTER TABLE model_capabilities
                ADD COLUMN device_type VARCHAR(32) NOT NULL DEFAULT '',
                ADD COLUMN capability_key VARCHAR(64) NOT NULL DEFAULT ''");
        }

        $pdo->exec('
            UPDATE model_capabilities mc
            JOIN capabilities c ON c.id = mc.capability_id
            SET mc.device_type = c.device_type, mc.capability_key = c.capability_key
        ');

        $orfas = (int)$pdo
            ->query("SELECT COUNT(*) FROM model_capabilities WHERE capability_key = ''")
            ->fetchColumn();
        if ($orfas > 0) {
            throw new \RuntimeException(
                "{$orfas} ligações apontam para um capability_id que não existe; "
                . 'a conversão para o par natural perdê-las-ia'
            );
        }

        // A chave primária muda de colunas, e a antiga chave estrangeira tem de sair antes
        // do `capability_id` que a sustenta.
        $this->dropConstraintIfPresent($pdo, 'fk_model_capabilities_capability_v2');
        $pdo->exec('ALTER TABLE model_capabilities
            DROP PRIMARY KEY,
            ADD PRIMARY KEY (model_id, device_type, capability_key)');
        $pdo->exec('ALTER TABLE model_capabilities DROP COLUMN capability_id');
        $pdo->exec("ALTER TABLE model_capabilities
            MODIFY device_type VARCHAR(32) NOT NULL,
            MODIFY capability_key VARCHAR(64) NOT NULL");

        if (!$this->hasIndex($pdo, 'idx_model_capabilities_capability')) {
            $pdo->exec('CREATE INDEX idx_model_capabilities_capability
                ON model_capabilities (device_type, capability_key)');
        }
        if (!$this->hasConstraint($pdo, 'fk_model_capabilities_capability_v3')) {
            $pdo->exec('
                ALTER TABLE model_capabilities
                ADD CONSTRAINT fk_model_capabilities_capability_v3
                    FOREIGN KEY (device_type, capability_key)
                    REFERENCES capabilities(device_type, capability_key)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ');
        }
    }

    private function dropConstraintIfPresent(PDO $pdo, string $constraint): void
    {
        if ($this->hasConstraint($pdo, $constraint)) {
            $pdo->exec("ALTER TABLE model_capabilities DROP FOREIGN KEY {$constraint}");
        }
    }

    private function hasColumn(PDO $pdo, string $column): bool
    {
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
        ');
        $stmt->execute(['model_capabilities', $column]);

        return (int)$stmt->fetchColumn() > 0;
    }

    private function hasIndex(PDO $pdo, string $index): bool
    {
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?
        ');
        $stmt->execute(['model_capabilities', $index]);

        return (int)$stmt->fetchColumn() > 0;
    }

    private function hasConstraint(PDO $pdo, string $constraint): bool
    {
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM information_schema.table_constraints
            WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = ?
        ');
        $stmt->execute(['model_capabilities', $constraint]);

        return (int)$stmt->fetchColumn() > 0;
    }
}
