<?php

declare(strict_types=1);

namespace Tests\Integration\Dashboard;

use Hub\Api\Repository\ApiDataAccess;
use Hub\Api\Repository\WhitelistRepository;
use Tests\Support\MysqlDashboardTestCase;

/**
 * O filtro por coluna de texto, que o descritor de colunas promete a quem consome a API.
 *
 * É distinto do `q`, que procura em cinco colunas ao mesmo tempo: aqui pergunta-se por uma
 * coluna em concreto, que é o que uma caixa de procura num cabeçalho quer dizer.
 */
final class WhitelistRepositoryTextFilterTest extends MysqlDashboardTestCase
{
    private function repositoryWithDevices(): WhitelistRepository
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $db->whitelist->register('865028000000306', 'Wonlex', 'HW20PRO', 'watch', 1001, '+351911111111', 'AAA-1', 'hitcare');
        $db->whitelist->register('865028000000307', 'Vivistar', 'L08 Pro', 'watch', 1001, '+351922222222', 'BBB-2', 'hitcare');
        $db->whitelist->register('414d74184cbf', 'Qinglanst', 'RD-V1', 'radar', 2004, '', 'AAA-3', 'havicare');

        return $db->whitelist;
    }

    /** @return list<string> */
    private function imeisMatching(WhitelistRepository $repository, array $filters): array
    {
        $result = $repository->listPage($filters, 1, 50);

        return array_map(static fn(array $row): string => (string)$row['imei'], $result['items']);
    }

    public function testFilteringByPartOfTheImeiNarrowsToThatColumn(): void
    {
        $repository = $this->repositoryWithDevices();

        self::assertSame(
            ['865028000000306', '865028000000307'],
            $this->imeisMatching($repository, ['imei' => '86502800000030']),
        );
    }

    public function testFilteringBySimNumberDoesNotMatchOtherColumns(): void
    {
        $repository = $this->repositoryWithDevices();

        self::assertSame(['865028000000307'], $this->imeisMatching($repository, ['simNumber' => '92222']));
    }

    public function testFilteringByDeviceIdIsCaseInsensitive(): void
    {
        $repository = $this->repositoryWithDevices();

        self::assertSame(
            ['414d74184cbf', '865028000000306'],
            $this->imeisMatching($repository, ['deviceId' => 'aaa']),
        );
    }

    /**
     * Um filtro de coluna e o `q` global ao mesmo tempo estreitam-se um ao outro, em vez de
     * um deles ganhar.
     */
    public function testAColumnFilterCombinesWithTheGlobalSearch(): void
    {
        $repository = $this->repositoryWithDevices();

        self::assertSame(
            ['865028000000306'],
            $this->imeisMatching($repository, ['deviceId' => 'aaa', 'q' => 'Wonlex']),
        );
    }

    public function testAnEmptyColumnFilterDoesNotNarrowAnything(): void
    {
        $repository = $this->repositoryWithDevices();

        self::assertCount(3, $this->imeisMatching($repository, ['imei' => '', 'simNumber' => '   ']));
    }

    /** O `%` de quem procura é texto, e não um curinga que devolve a lista toda. */
    public function testAWildcardTypedByTheUserIsTreatedAsText(): void
    {
        $repository = $this->repositoryWithDevices();

        self::assertSame([], $this->imeisMatching($repository, ['imei' => '%']));
    }
}
