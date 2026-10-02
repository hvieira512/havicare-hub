<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * As chaves estrangeiras que apontam para o `whitelist.imei` passam a acompanhar a renomeação
 * da chave, e não só a remoção da linha.
 */
final class WhitelistKeyCascadesOnRename implements Migration
{
    /** Cada chave com a sua tabela e a sua coluna, pela ordem em que são recriadas. */
    private const KEYS = [
        ['gateway_device_links', 'fk_gateway_device_links_gateway', 'gateway_device_key'],
        ['gateway_device_links', 'fk_gateway_device_links_device', 'linked_device_key'],
        ['radar_layouts', 'fk_radar_layouts_device', 'imei'],
    ];

    public function version(): string
    {
        return 'whitelist_key_cascades_on_rename';
    }

    public function up(PDO $pdo): void
    {
        foreach (self::KEYS as [$table, $constraint, $column]) {
            if ($this->cascadesOnUpdate($pdo, $table, $constraint)) {
                continue;
            }

            $pdo->exec("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraint}`");
            $pdo->exec(
                "ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` FOREIGN KEY (`{$column}`) "
                . 'REFERENCES whitelist(imei) ON DELETE CASCADE ON UPDATE CASCADE'
            );
        }
    }

    private function cascadesOnUpdate(PDO $pdo, string $table, string $constraint): bool
    {
        $stmt = $pdo->prepare('
            SELECT update_rule
            FROM information_schema.referential_constraints
            WHERE constraint_schema = DATABASE() AND table_name = ? AND constraint_name = ?
        ');
        $stmt->execute([$table, $constraint]);
        $rule = $stmt->fetchColumn();

        return $rule === false || $rule === 'CASCADE';
    }
}
