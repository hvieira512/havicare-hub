<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use Hub\Infrastructure\Persistence\ReferenceCatalogSeeder;
use PDO;

/**
 * Traz a tabela `capabilities` ao que o código passou a declarar.
 *
 * Três coisas mudaram no `CapabilityCatalog` e a leitura já as reflecte -- o
 * `GenericCapabilityRepository` acrescenta em memória, com `id` nulo, as definições que ainda
 * não têm linha. O que falta é a linha, e sem ela não há `capability_id` para o
 * `model_capabilities` apontar: as capacidades aparecem mas não se conseguem desligar num
 * modelo em particular.
 *
 * 1. O NCS declarava `pager_call` e o normalizador sempre publicou `help_call`. A chave é
 *    **renomeada** e não apagada e recriada, para o `id` sobreviver: a linha que liga o
 *    Voerka W812 a esta capacidade aponta para ele, e um `DELETE` levava-a por arrasto.
 * 2. O `alarm` dos relógios e a `proximity` das pulseiras e dos medidores de fraldas passam a
 *    ter linha.
 * 3. Os modelos cujo protocolo as suporta ganham-nas, pelo mesmo preenchimento de lacunas que
 *    uma base nova recebe -- só insere o que falta, e não volta a ligar o que alguém desligou.
 *
 * É idempotente: correr outra vez não muda nada.
 */
final class CatalogAlarmProximityAndHelpCall implements Migration
{
    public function version(): string
    {
        return '2026_09_01_catalog_alarm_proximity_help_call';
    }

    public function up(PDO $pdo): void
    {
        // Numa base vazia não há nada a trazer a dia, e mexer aqui parti-la-ia: o
        // `DatabaseMigrator` corre as migrações **antes** do semeador, e o semeador só corre
        // quando a tabela `capabilities` está vazia. Escrever nela aqui fazia a guarda saltar,
        // e uma instalação de raiz nascia sem fornecedores, sem modelos e sem empresas.
        if ((int)$pdo->query('SELECT COUNT(*) FROM capabilities')->fetchColumn() === 0) {
            return;
        }

        $this->renamePagerCall($pdo);

        $seeder = new ReferenceCatalogSeeder();
        $seeder->syncCapabilityCatalog($pdo);
        $seeder->seedMissingModelCapabilities($pdo);
    }

    /**
     * O `UPDATE` só corre se o destino ainda não existir.
     *
     * A tabela tem chave única em (`device_type`, `capability_key`), e numa base onde o
     * `help_call` do NCS já tenha sido criado -- por uma instalação de raiz, por exemplo -- o
     * `UPDATE` rebentaria. Aí a linha antiga é a que sobra, e sai.
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
