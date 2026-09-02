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
        self::assertSame(['column' => 'imei', 'descending' => false], $this->sort([]));
    }

    public function testAnAllowedColumnIsAccepted(): void
    {
        self::assertSame(['column' => 'company', 'descending' => false], $this->sort(['sort' => 'company']));
    }

    public function testALeadingMinusAsksForDescending(): void
    {
        self::assertSame(['column' => 'company', 'descending' => true], $this->sort(['sort' => '-company']));
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
            ['column' => 'imei', 'descending' => false],
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
            'injecção com vírgula' => ['imei, (SELECT 1)'],
            'injecção com espaço' => ['imei DESC, company'],
            'comentário SQL' => ['imei--'],
            'ponto e vírgula' => ['imei; DROP TABLE whitelist'],
            'vazio' => [''],
            'só o sinal' => ['-'],
        ];
    }

    public function testTheDirectionSurvivesAnUppercaseColumn(): void
    {
        self::assertSame(['column' => 'model', 'descending' => true], $this->sort(['sort' => '-MODEL']));
    }

    public function testAnArrayIsNotAColumn(): void
    {
        self::assertSame(['column' => 'imei', 'descending' => false], $this->sort(['sort' => ['company']]));
    }
}
