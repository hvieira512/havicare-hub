<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use Hub\Infrastructure\Persistence\ReferenceCatalogSeeder;
use PDO;

/**
 * Traz a tabela `capabilities` ao que o `CapabilityCatalog` declara. A leitura já acrescenta
 * as definições em falta com `id` nulo; o que falta é a linha, sem a qual não há
 * `capability_id` para o `model_capabilities` apontar.
 *
 * O `pager_call` do NCS é **renomeado** para `help_call` e não apagado e recriado, para o
 * `id` sobreviver à linha que liga o Voerka W812 a ele. O `alarm` e a `proximity` ganham
 * linha, e os modelos cujo protocolo as suporta ganham-nas por preenchimento de lacunas.
 *
 * É idempotente.
 */
final class CatalogAlarmProximityAndHelpCall implements Migration
{
    public function version(): string
    {
        return '2026_09_01_catalog_alarm_proximity_help_call';
    }

    public function up(PDO $pdo): void
    {
        // Numa base vazia não há nada a fazer, e escrever aqui parti-la-ia: as migrações
        // correm antes do semeador, e o semeador só corre com a tabela vazia. Uma linha aqui
        // fazia uma instalação de raiz nascer sem fornecedores nem modelos.
        if ((int)$pdo->query('SELECT COUNT(*) FROM capabilities')->fetchColumn() === 0) {
            return;
        }

        $this->renamePagerCall($pdo);

        $seeder = new ReferenceCatalogSeeder();
        $seeder->syncCapabilityCatalog($pdo);
        $seeder->seedMissingModelCapabilities($pdo);
    }

    /**
     * O `UPDATE` só corre se o destino não existir: a chave única em (`device_type`,
     * `capability_key`) rebentava numa base onde o `help_call` já tenha sido criado. Aí é a
     * linha antiga que sai.
     */
    private function renamePagerCall(PDO $pdo): void
    {
        $existing = $pdo->prepare('SELECT id FROM capabilities WHERE device_type = ? AND capability_key = ?');

        $existing->execute(['ncs', 'pager_call']);
        if ((int)($existing->fetchColumn() ?: 0) === 0) {
            return;
        }

        $existing->execute(['ncs', 'help_call']);
        if ((int)($existing->fetchColumn() ?: 0) > 0) {
            $pdo->prepare('DELETE FROM capabilities WHERE device_type = ? AND capability_key = ?')
                ->execute(['ncs', 'pager_call']);
            return;
        }

        $pdo->prepare('UPDATE capabilities SET capability_key = ? WHERE device_type = ? AND capability_key = ?')
            ->execute(['help_call', 'ncs', 'pager_call']);
    }
}
