<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use Hub\Api\Http\ApiUserColumns;
use Hub\Api\OpenApiSpec;
use PHPUnit\Framework\TestCase;

/**
 * A listagem de utilizadores da API passou a ordenar-se, a filtrar-se por coluna e a
 * descrever as próprias colunas. É superfície pública, e quem integra lê a especificação:
 * um parâmetro que o serviço aceita e o documento não menciona é uma capacidade que
 * ninguém sabe que existe.
 */
final class OpenApiSpecApiUsersTest extends TestCase
{
    /** @return array<string, mixed> */
    private function parameter(string $name): array
    {
        $spec = OpenApiSpec::get();
        foreach ($spec['paths']['/api/users']['get']['parameters'] as $parameter) {
            if (($parameter['name'] ?? '') === $name) {
                return $parameter;
            }
        }

        self::fail(sprintf('o parâmetro "%s" não está documentado no GET /api/users', $name));
    }

    /** O sentido escreve-se por extenso, e as colunas separam-se por vírgula pela precedência. */
    public function testTheSortParameterIsDocumented(): void
    {
        $sort = $this->parameter('sort');

        self::assertFalse($sort['required'] ?? true);
        self::assertSame('string', $sort['schema']['type']);
        self::assertStringContainsString('username:asc', (string)($sort['schema']['example'] ?? ''));
    }

    /** Cada filtro que o descritor anuncia tem de ter um parâmetro que o receba. */
    public function testEveryFilterTheDescriptorAnnouncesIsDocumented(): void
    {
        foreach (ApiUserColumns::definition()->describe() as $column) {
            if ($column['filter'] === null) {
                continue;
            }
            $documented = $this->parameter($column['filter']['param']);
            self::assertSame('string', $documented['schema']['type']);
        }
    }

    /** As colunas viajam na resposta, e o esquema tem de as prever. */
    public function testTheResponseSchemaCarriesTheColumns(): void
    {
        $spec = OpenApiSpec::get();
        $schema = $spec['components']['schemas']['ApiUserListResponse'];

        self::assertArrayHasKey('columns', $schema['properties']);
        self::assertSame('array', $schema['properties']['columns']['type']);
    }
}
