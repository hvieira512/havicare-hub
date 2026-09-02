<?php

declare(strict_types=1);

namespace Tests\Unit\Api\Http;

use Hub\Api\Http\CollectionQuery;
use PHPUnit\Framework\TestCase;

final class CollectionQuerySortTest extends TestCase
{
    private CollectionQuery $query;

    protected function setUp(): void
    {
        $this->query = new CollectionQuery();
    }

    /** @param array<string, mixed> $params */
    private function sort(array $params): array
    {
        return $this->query->sort($params, ['imei', 'company', 'model'], 'imei');
    }

    public function testTheDefaultColumnIsUsedWhenNothingIsAsked(): void
    {
        self::assertSame([['column' => 'imei', 'descending' => false]], $this->sort([]));
    }

    public function testAnAllowedColumnIsAccepted(): void
    {
        self::assertSame([['column' => 'company', 'descending' => false]], $this->sort(['sort' => 'company']));
    }

    /** O sentido vem escrito, e não num sinal à frente do nome. */
    public function testTheDirectionIsWrittenNextToTheColumn(): void
    {
        self::assertSame([['column' => 'company', 'descending' => true]], $this->sort(['sort' => 'company:desc']));
        self::assertSame([['column' => 'company', 'descending' => false]], $this->sort(['sort' => 'company:asc']));
    }


    /** Sem sentido escrito, ascendente -- que é o que ordenar por uma coluna quer dizer. */
    public function testAColumnWithoutADirectionIsAscending(): void
    {
        self::assertSame([['column' => 'company', 'descending' => false]], $this->sort(['sort' => 'company']));
    }

    /** Várias colunas por vírgula, pela ordem em que se escreveram: a primeira manda. */
    public function testSeveralColumnsAreAcceptedInTheOrderTheyWereWritten(): void
    {
        self::assertSame(
            [
                ['column' => 'company', 'descending' => true],
                ['column' => 'model', 'descending' => false],
            ],
            $this->sort(['sort' => 'company:desc,model:asc']),
        );
    }

    /** Uma coluna má no meio de boas não deita fora as outras: cai só ela. */
    public function testAnUnknownColumnIsDroppedAndTheRestSurvive(): void
    {
        self::assertSame(
            [['column' => 'company', 'descending' => false], ['column' => 'model', 'descending' => true]],
            $this->sort(['sort' => 'company:asc,inventada:asc,model:desc']),
        );
    }

    /** A mesma coluna duas vezes não a ordena duas vezes: a primeira menção é que vale. */
    public function testTheSameColumnTwiceCountsOnce(): void
    {
        self::assertSame(
            [['column' => 'company', 'descending' => false]],
            $this->sort(['sort' => 'company:asc,company:desc']),
        );
    }

    public function testSpacesAroundTheCommasAreTolerated(): void
    {
        self::assertSame(
            [['column' => 'company', 'descending' => false], ['column' => 'imei', 'descending' => true]],
            $this->sort(['sort' => ' company:asc , imei:desc ']),
        );
    }

    /**
     * O valor vai parar a um `ORDER BY`, e por isso a allowlist é a fronteira: o que não
     * está nela não passa mutilado, cai para a coluna por omissão.
     *
     * @dataProvider rejectedValues
     */
    public function testWhatIsNotOnTheAllowlistFallsBackToTheDefault(string $value): void
    {
        self::assertSame(
            [['column' => 'imei', 'descending' => false]],
            $this->sort(['sort' => $value]),
            sprintf('"%s" não pode ser aceite como coluna', $value),
        );
    }

    /** @return array<string, array{string}> */
    public static function rejectedValues(): array
    {
        return [
            'coluna inventada' => ['inventada'],
            'coluna de outra tabela' => ['l.name'],
            'comentário SQL' => ['imei--'],
            'ponto e vírgula' => ['imei; DROP TABLE whitelist'],
            'vazio' => [''],
            'só o separador' => [':'],
            'sentido inventado' => ['company:acima'],
            // O `-coluna` não é a forma desta API: o sentido escreve-se por extenso.
            'sinal à frente do nome' => ['-company'],
        ];
    }

    /**
     * Uma tentativa de injecção que traz uma coluna válida atrás não passa a injecção: o
     * `imei DESC` não está na allowlist e cai, e sobra o `company` que estava.
     *
     * @dataProvider injectionsThatCarryAValidColumn
     */
    public function testAnInjectionKeepsOnlyTheColumnsTheAllowlistRecognises(string $value, array $expected): void
    {
        self::assertSame($expected, $this->sort(['sort' => $value]));
    }

    /** @return array<string, array{string, list<array{column: string, descending: bool}>}> */
    public static function injectionsThatCarryAValidColumn(): array
    {
        return [
            'espaço depois da coluna' => ['imei DESC, company:asc', [['column' => 'company', 'descending' => false]]],
            'subconsulta' => ['imei:asc, (SELECT 1)', [['column' => 'imei', 'descending' => false]]],
        ];
    }

    public function testTheDirectionSurvivesAnUppercaseColumn(): void
    {
        self::assertSame([['column' => 'model', 'descending' => true]], $this->sort(['sort' => 'MODEL:DESC']));
    }

    public function testAnArrayIsNotAColumn(): void
    {
        self::assertSame([['column' => 'imei', 'descending' => false]], $this->sort(['sort' => ['company']]));
    }
}
