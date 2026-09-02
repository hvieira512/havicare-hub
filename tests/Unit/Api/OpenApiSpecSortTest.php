<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use Hub\Api\OpenApiSpec;
use Hub\Api\Repository\WhitelistRepository;
use PHPUnit\Framework\TestCase;

/**
 * O `sort` é contrato público: quem integra lê a especificação para saber por que colunas
 * pode ordenar. Isto prende a especificação à allowlist do repositório, para uma coluna
 * nova não ficar por documentar nem uma removida a ser prometida a quem já não a tem.
 */
final class OpenApiSpecSortTest extends TestCase
{
    /** @return array<string, mixed> */
    private function sortParameter(): array
    {
        $spec = OpenApiSpec::get();
        $parameters = $spec['paths']['/api/devices']['get']['parameters'] ?? [];

        foreach ($parameters as $parameter) {
            if (($parameter['name'] ?? null) === 'sort') {
                return $parameter;
            }
        }

        self::fail('A listagem de dispositivos não declara o parâmetro `sort`.');
    }

    public function testTheDeclaredColumnsAreExactlyTheOnesTheRepositoryAllows(): void
    {
        $declared = $this->sortParameter()['schema']['enum'] ?? [];

        $expected = [];
        foreach (array_keys(WhitelistRepository::SORTABLE_COLUMNS) as $column) {
            $expected[] = $column;
            $expected[] = '-' . $column;
        }

        sort($declared);
        sort($expected);

        self::assertSame($expected, $declared);
    }

    public function testTheParameterIsOptionalAndDefaultsToImei(): void
    {
        $parameter = $this->sortParameter();

        self::assertFalse($parameter['required'] ?? true);
        self::assertSame('imei', $parameter['schema']['default'] ?? null);
    }
}
