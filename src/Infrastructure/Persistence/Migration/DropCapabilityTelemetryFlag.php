<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * Larga o `capabilities.is_telemetry`, que repetia a secção.
 *
 * Nas 93 linhas do catálogo, `is_telemetry = 1` e `section = 'telemetry'` coincidiam sem uma
 * única excepção, e as duas eram declaradas lado a lado em cada definição em código. A
 * consulta passa a calcular `(section = 'telemetry') AS is_telemetry`, pelo que o campo
 * `isTelemetry` da API -- que o ecrã das capacidades usa para decidir o que mostra -- não
 * muda de valor nem de nome.
 *
 * Desiste se alguma linha discordar: nesse caso a coluna carrega informação que a secção não
 * tem, e largá-la perdia-a. Em produção não discorda nenhuma.
 */
final class DropCapabilityTelemetryFlag implements Migration
{
    public function version(): string
    {
        return '2026_09_04_drop_capability_telemetry_flag';
    }

    public function up(PDO $pdo): void
    {
        if (!$this->hasColumn($pdo)) {
            return;
        }

        $divergentes = (int)$pdo
            ->query("SELECT COUNT(*) FROM capabilities WHERE (section = 'telemetry') <> is_telemetry")
            ->fetchColumn();
        if ($divergentes > 0) {
            throw new \RuntimeException(
                "capabilities.is_telemetry discorda da secção em {$divergentes} linhas; "
                . 'a coluna não pode ser largada sem perder essa informação'
            );
        }

        $pdo->exec('ALTER TABLE capabilities DROP COLUMN is_telemetry');
    }

    private function hasColumn(PDO $pdo): bool
    {
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
        ');
        $stmt->execute(['capabilities', 'is_telemetry']);

        return (int)$stmt->fetchColumn() > 0;
    }
}
