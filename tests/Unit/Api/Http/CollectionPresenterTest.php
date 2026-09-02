<?php

declare(strict_types=1);

namespace Tests\Unit\Api\Http;

use Hub\Api\Http\CollectionColumns;
use Hub\Api\Http\CollectionPresenter;
use PHPUnit\Framework\TestCase;

/**
 * O motor das listagens servidas de uma vez: filtra, ordena, conta e só depois pagina.
 *
 * A ordem importa. Contar antes de filtrar dava números que não correspondiam ao que se vê,
 * e paginar antes de ordenar deixava a página 2 com linhas da ordem anterior.
 */
final class CollectionPresenterTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function users(): array
    {
        return [
            ['username' => 'marcus', 'role' => 'hub_admin', 'enabled' => 1],
            ['username' => 'havicare', 'role' => 'license_client', 'enabled' => 1],
            ['username' => 'hitcare', 'role' => 'hub_admin', 'enabled' => 0],
            ['username' => 'admin', 'role' => 'hub_admin', 'enabled' => 1],
        ];
    }

    private function columns(): CollectionColumns
    {
        return new CollectionColumns(
            sortable: ['username' => 'username', 'role' => 'role', 'enabled' => 'enabled'],
            textFilters: ['username' => 'username'],
            fixedOptions: [
                'role' => ['hub_admin', 'license_client'],
                'enabled' => ['1', '0'],
            ],
        );
    }

    private function present(array $params): array
    {
        return (new CollectionPresenter())->present($this->users(), $this->columns(), $params);
    }

    /** @return list<string> */
    private function names(array $result): array
    {
        return array_map(static fn(array $row): string => $row['username'], $result['data']);
    }

    public function testWithoutParametersEverythingComesBackInTheGivenOrder(): void
    {
        self::assertSame(['marcus', 'havicare', 'hitcare', 'admin'], $this->names($this->present([])));
    }

    public function testSortingByOneColumn(): void
    {
        self::assertSame(
            ['admin', 'havicare', 'hitcare', 'marcus'],
            $this->names($this->present(['sort' => 'username:asc'])),
        );
    }

    public function testSortingByTwoColumnsUsesTheSecondToBreakTies(): void
    {
        $rows = $this->present(['sort' => 'role:asc,username:desc'])['data'];

        self::assertSame(
            [['hub_admin', 'marcus'], ['hub_admin', 'hitcare'], ['hub_admin', 'admin'], ['license_client', 'havicare']],
            array_map(static fn(array $r): array => [$r['role'], $r['username']], $rows),
        );
    }

    public function testATextFilterMatchesPartOfTheValueAndIgnoresCase(): void
    {
        self::assertSame(['havicare', 'hitcare'], $this->names($this->present(['username' => 'CARE'])));
    }

    /** Dois filtros ao mesmo tempo estreitam-se um ao outro. */
    public function testFiltersCombine(): void
    {
        self::assertSame(['hitcare'], $this->names($this->present(['role' => 'hub_admin', 'username' => 'care'])));
    }

    /** As contagens são do que o filtro deixou, senão prometem linhas que não existem. */
    public function testTheCountsDescribeWhatTheFilterLeft(): void
    {
        $result = $this->present(['username' => 'care']);
        $options = [];
        foreach ($result['columns'] as $column) {
            if ($column['field'] === 'role') {
                $options = $column['filter']['options'];
            }
        }

        self::assertSame(
            [['value' => 'hub_admin', 'count' => 1], ['value' => 'license_client', 'count' => 1]],
            $options,
        );
    }

    public function testPaginationSlicesAfterSortingAndNotBefore(): void
    {
        $second = $this->present(['sort' => 'username:asc', 'page' => 2, 'limit' => 2]);

        self::assertSame(['hitcare', 'marcus'], $this->names($second));
        self::assertSame(['limit' => 2, 'page' => 2, 'total_pages' => 2, 'total' => 4], $second['pagination']);
    }

    /** O total conta o que o filtro deixou, e não a colecção inteira. */
    public function testTheTotalFollowsTheFilter(): void
    {
        self::assertSame(2, $this->present(['username' => 'care'])['pagination']['total']);
    }

    /**
     * Um conjunto fechado oferece-se inteiro, mesmo quando os dados só têm um dos valores:
     * com todos os utilizadores activos, um dropdown tirado das linhas nunca deixaria
     * escolher "inactivo", e o filtro ficava inalcançável.
     */
    public function testAClosedSetOffersEveryValueAndCountsWhatIsThere(): void
    {
        $options = [];
        foreach ($this->present(['role' => 'hub_admin'])['columns'] as $column) {
            if ($column['field'] === 'enabled') {
                $options = $column['filter']['options'];
            }
        }

        self::assertSame(
            [['value' => '1', 'count' => 2], ['value' => '0', 'count' => 1]],
            $options,
        );
    }

    /** E um conjunto fechado também filtra. */
    public function testAClosedSetFilters(): void
    {
        self::assertSame(['hitcare'], $this->names($this->present(['enabled' => '0'])));
    }

    /**
     * Cada faceta conta-se sem o seu próprio filtro. Escolher um papel tem de continuar a
     * mostrar o outro no dropdown, senão quem escolheu fica lá preso -- e as contagens dos
     * outros filtros continuam a estreitar-se, que é o que as torna úteis.
     */
    public function testASelectFacetIgnoresItsOwnFilterButRespectsTheOthers(): void
    {
        $optionsFor = static function (array $result, string $field): array {
            foreach ($result['columns'] as $column) {
                if ($column['field'] === $field) {
                    return $column['filter']['options'];
                }
            }
            return [];
        };

        $result = $this->present(['role' => 'license_client']);

        // O próprio filtro não se conta a si: os dois papéis continuam à escolha.
        self::assertSame(
            [['value' => 'hub_admin', 'count' => 3], ['value' => 'license_client', 'count' => 1]],
            $optionsFor($result, 'role'),
        );

        // Mas o estado já reflecte a escolha: só o havicare sobrou, e está activo.
        self::assertSame(
            [['value' => '1', 'count' => 1], ['value' => '0', 'count' => 0]],
            $optionsFor($result, 'enabled'),
        );
    }

    public function testTheDescriptorTravelsWithTheResponse(): void
    {
        $fields = array_column($this->present([])['columns'], 'field');

        self::assertSame(['username', 'role', 'enabled'], $fields);
    }
}
