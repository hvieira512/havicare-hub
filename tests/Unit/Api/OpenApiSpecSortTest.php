<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use Hub\Api\Http\DeviceColumns;
use Hub\Api\OpenApiSpec;
use PHPUnit\Framework\TestCase;

/**
 * A ordenação e o descritor de colunas são contrato público: quem integra lê a
 * especificação para saber o que pode pedir. Isto prende-a ao que o servidor faz mesmo.
 */
final class OpenApiSpecSortTest extends TestCase
{
    /** @return array<string, mixed> */
    private function parameter(string $name): array
    {
        $parameters = OpenApiSpec::get()['paths']['/api/devices']['get']['parameters'] ?? [];
        foreach ($parameters as $parameter) {
            if (($parameter['name'] ?? null) === $name) {
                return $parameter;
            }
        }

        self::fail(sprintf('A listagem de dispositivos não declara o parâmetro `%s`.', $name));
    }

    /**
     * Deixou de ser um `enum`: com várias colunas por vírgula, o conjunto de valores válidos
     * é combinatório. A lista autoritativa passou a viajar no `columns` da resposta.
     */
    public function testTheSortParameterIsAFreeStringThatDocumentsTheCommaForm(): void
    {
        $sort = $this->parameter('sort');

        self::assertFalse($sort['required'] ?? true);
        self::assertSame('string', $sort['schema']['type']);
        self::assertArrayNotHasKey('enum', $sort['schema']);
        self::assertSame('imei', $sort['schema']['default']);
        self::assertStringContainsString(',', (string)($sort['description'] ?? ''));
    }

    /** Cada coluna de texto filtrável tem de estar declarada como parâmetro. */
    public function testEveryTextFilterColumnIsDeclaredAsAQueryParameter(): void
    {
        foreach (array_keys(DeviceColumns::TEXT_FILTERS) as $field) {
            $parameter = $this->parameter($field);
            self::assertSame('string', $parameter['schema']['type'], $field . ' devia ser texto');
        }
    }

    /** A resposta da listagem anuncia o descritor, senão ninguém sabe que existe. */
    public function testTheListResponseDeclaresTheColumnDescriptor(): void
    {
        $schemas = OpenApiSpec::get()['components']['schemas'] ?? [];
        $properties = $schemas['DeviceListResponse']['properties'] ?? [];

        self::assertArrayHasKey('columns', $properties);
        self::assertSame('array', $properties['columns']['type']);
        self::assertArrayHasKey('CollectionColumn', $schemas);

        $column = $schemas['CollectionColumn']['properties'] ?? [];
        foreach (['field', 'sortable', 'editable', 'filter'] as $key) {
            self::assertArrayHasKey($key, $column, 'o descritor tem de declarar ' . $key);
        }
    }
}
