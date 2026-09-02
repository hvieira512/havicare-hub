<?php

declare(strict_types=1);

namespace Tests\Integration\Dashboard;

use Hub\Api\Repository\ApiDataAccess;
use Hub\Api\Repository\WhitelistRepository;
use Tests\Support\MysqlDashboardTestCase;

final class WhitelistRepositorySortTest extends MysqlDashboardTestCase
{
    private function repositoryWithDevices(): WhitelistRepository
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());

        // Doze aparelhos em três empresas: a coluna por que se ordena tem valores repetidos
        // de propósito, que é a condição em que a ordem deixa de ser determinada.
        $devices = [
            ['100000000000001', 'hitcare'],
            ['100000000000002', 'hitcare'],
            ['100000000000003', 'hitcare'],
            ['100000000000004', 'hitcare'],
            ['100000000000005', 'havicare'],
            ['100000000000006', 'havicare'],
            ['100000000000007', 'havicare'],
            ['100000000000008', 'havicare'],
            ['100000000000009', 'gerpi'],
            ['100000000000010', 'gerpi'],
            ['100000000000011', 'gerpi'],
            ['100000000000012', 'gerpi'],
        ];

        foreach ($devices as [$imei, $company]) {
            $db->whitelist->register($imei, 'Vivistar', 'L08 Pro', 'watch', 1001, '', '', $company);
        }

        return $db->whitelist;
    }

    /** @return list<string> */
    private function imeisOfPage(WhitelistRepository $repository, int $page, int $limit, array $sort): array
    {
        $result = $repository->listPage([], $page, $limit, null, null, $sort);

        return array_map(static fn(array $row): string => (string)$row['imei'], $result['items']);
    }

    public function testSortingByAnAllowedColumnChangesTheOrder(): void
    {
        $repository = $this->repositoryWithDevices();

        $ascending = $repository->listPage([], 1, 12, null, null, ['column' => 'company', 'descending' => false]);
        $descending = $repository->listPage([], 1, 12, null, null, ['column' => 'company', 'descending' => true]);

        $first = static fn(array $result): string => (string)$result['items'][0]['company'];

        self::assertSame('gerpi', $first($ascending));
        self::assertSame('hitcare', $first($descending));
    }

    /**
     * O defeito que este teste prende: sem desempate, duas consultas com o mesmo `ORDER BY`
     * sobre valores repetidos podem devolver as linhas por ordem diferente. Percorrer as
     * páginas passa então a repetir umas linhas e a perder outras, sem erro nenhum.
     */
    public function testWalkingEveryPageOfASortedListReturnsEachDeviceExactlyOnce(): void
    {
        $repository = $this->repositoryWithDevices();
        $sort = ['column' => 'company', 'descending' => false];

        $seen = [];
        for ($page = 1; $page <= 4; $page++) {
            foreach ($this->imeisOfPage($repository, $page, 3, $sort) as $imei) {
                $seen[] = $imei;
            }
        }

        self::assertCount(12, $seen, 'as quatro páginas de três têm de dar doze linhas');
        self::assertSame(
            array_values(array_unique($seen)),
            $seen,
            'nenhum aparelho pode aparecer em duas páginas',
        );
    }

    /** As páginas de uma lista ordenada não podem mudar entre duas leituras iguais. */
    public function testTheSamePageReadTwiceGivesTheSameRows(): void
    {
        $repository = $this->repositoryWithDevices();
        $sort = ['column' => 'company', 'descending' => false];

        self::assertSame(
            $this->imeisOfPage($repository, 2, 3, $sort),
            $this->imeisOfPage($repository, 2, 3, $sort),
        );
    }

    /**
     * Um dispositivo sem dono tem `NULL` na empresa, e o MariaDB põe `NULL` à frente em
     * ascendente. Isso trocava os sem-dono pelos primeiros da lista, e contradizia o
     * `sortRows` da dashboard, que põe a falta no fim de propósito nos dois sentidos.
     */
    public function testDevicesWithoutACompanyGoLastInBothDirections(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $db->whitelist->register('100000000000020', 'Vivistar', 'L08 Pro', 'watch', 1001, '', '', 'hitcare');
        $db->whitelist->register('100000000000021', 'Vivistar', 'L08 Pro', 'watch', 1001, '', '', 'havicare');
        $db->whitelist->register('100000000000022', 'Vivistar', 'L08 Pro');

        // A coluna guarda `NULL`; é o repositório que o traduz para o sentinela `'null'` ao
        // sair, e por isso é o sentinela que se vê aqui.
        $companies = static fn(array $result): array => array_map(
            static fn(array $row): ?string => $row['company'],
            $result['items'],
        );

        $ascending = $companies($db->whitelist->listPage([], 1, 10, null, null, ['column' => 'company', 'descending' => false]));
        $descending = $companies($db->whitelist->listPage([], 1, 10, null, null, ['column' => 'company', 'descending' => true]));

        self::assertSame(['havicare', 'hitcare', 'null'], $ascending);
        self::assertSame(['hitcare', 'havicare', 'null'], $descending);
    }

    /** Sem ordenação pedida, a listagem mantém a ordem por IMEI que sempre teve. */
    public function testWithoutASortTheOrderIsStillByImei(): void
    {
        $repository = $this->repositoryWithDevices();

        $imeis = $this->imeisOfPage($repository, 1, 12, ['column' => 'imei', 'descending' => false]);
        $sorted = $imeis;
        sort($sorted);

        self::assertSame($sorted, $imeis);
    }

    /**
     * A allowlist vive no `CollectionQuery`, mas o repositório é a última porta antes do SQL
     * e não pode aceitar uma coluna que não conheça.
     */
    public function testAColumnTheRepositoryDoesNotKnowIsIgnored(): void
    {
        $repository = $this->repositoryWithDevices();

        $imeis = $this->imeisOfPage($repository, 1, 12, ['column' => 'imei; DROP TABLE whitelist', 'descending' => false]);

        self::assertCount(12, $imeis);
    }
}
