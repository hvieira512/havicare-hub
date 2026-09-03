<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * Larga o `api_users.license_id`, que repetia o número da licença apontada.
 *
 * O `resolveLicense()` do serviço já derivava as duas colunas da mesma linha de `licenses`,
 * pelo que nunca podiam divergir por esse caminho. O `SELECT` passa a ler `l.license_id` do
 * `JOIN` que já existia, e o campo continua na resposta da API.
 *
 * **Este valor é o âmbito do inquilino**, e no `WhitelistRepository` um âmbito nulo não
 * significa "licença desconhecida" -- significa "sem filtro", ou seja, todos os clientes. Por
 * isso a migração desiste se encontrar uma conta de cliente cuja referência não resolva para
 * o número que a coluna guardava: aí a troca mudaria o âmbito dessa conta, e é preferível
 * falhar a migração do que alargar um âmbito em silêncio.
 */
final class DropApiUserLicenseNumber implements Migration
{
    public function version(): string
    {
        return '2026_09_04_drop_api_user_license_number';
    }

    public function up(PDO $pdo): void
    {
        if (!$this->hasColumn($pdo)) {
            return;
        }

        $divergentes = (int)$pdo
            ->query("
                SELECT COUNT(*)
                FROM api_users u
                LEFT JOIN licenses l ON l.id = u.license_ref_id
                WHERE u.role = 'license_client'
                  AND NOT (u.license_id <=> l.license_id)
            ")
            ->fetchColumn();
        if ($divergentes > 0) {
            throw new \RuntimeException(
                "{$divergentes} contas de cliente têm um license_id que a referência não "
                . 'reproduz; largar a coluna mudaria o âmbito delas'
            );
        }

        if ($this->hasIndex($pdo, 'idx_api_users_role_license')) {
            $pdo->exec('DROP INDEX idx_api_users_role_license ON api_users');
        }
        $pdo->exec('ALTER TABLE api_users DROP COLUMN license_id');
    }

    private function hasColumn(PDO $pdo): bool
    {
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
        ');
        $stmt->execute(['api_users', 'license_id']);

        return (int)$stmt->fetchColumn() > 0;
    }

    private function hasIndex(PDO $pdo, string $index): bool
    {
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?
        ');
        $stmt->execute(['api_users', $index]);

        return (int)$stmt->fetchColumn() > 0;
    }
}
