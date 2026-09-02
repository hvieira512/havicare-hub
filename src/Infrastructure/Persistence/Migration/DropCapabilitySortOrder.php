<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * Larga o `capabilities.sort_order` de uma base que já existia.
 *
 * A coluna guardava um inteiro escolhido à mão que decidia por que ordem as capacidades
 * apareciam dentro de uma secção. Não era editável em lado nenhum -- vinha só das definições
 * em código --, não era lida pelo cliente, e a ordem que produzia não se explicava por nada
 * do que estava no ecrã. A ordem passou a ser alfabética pela etiqueta, feita na consulta.
 *
 * A ordem das secções não vinha daqui: é uma lista fixa no `ORDER BY` e continua igual.
 *
 * O índice que a tinha na ponta é substituído pelo equivalente com a etiqueta, que é agora o
 * que a consulta ordena.
 */
final class DropCapabilitySortOrder implements Migration
{
    public function version(): string
    {
        return '2026_09_02_drop_capability_sort_order';
    }

    public function up(PDO $pdo): void
    {
        if ($this->hasIndex($pdo, 'idx_capabilities_device_type_section_order')) {
            $pdo->exec('DROP INDEX idx_capabilities_device_type_section_order ON capabilities');
        }

        if (!$this->hasIndex($pdo, 'idx_capabilities_device_type_section_label')) {
            $pdo->exec('CREATE INDEX idx_capabilities_device_type_section_label
                ON capabilities (device_type, section, label)');
        }

        if ($this->hasColumn($pdo, 'sort_order')) {
            $pdo->exec('ALTER TABLE capabilities DROP COLUMN sort_order');
        }
    }

    private function hasColumn(PDO $pdo, string $column): bool
    {
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
        ');
        $stmt->execute(['capabilities', $column]);

        return (int)$stmt->fetchColumn() > 0;
    }

    private function hasIndex(PDO $pdo, string $index): bool
    {
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?
        ');
        $stmt->execute(['capabilities', $index]);

        return (int)$stmt->fetchColumn() > 0;
    }
}
