<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use Hub\Api\OpenApiSpec;
use PHPUnit\Framework\TestCase;

/**
 * A especificação é montada a partir de ficheiros por domínio, e por isso um esquema e a rota
 * que o referencia editam-se longe um do outro. Isto prende os dois: um nome de esquema mal
 * escrito ou apagado parte a build em vez de sair um documento cujos `$ref` não resolvem, e
 * um esquema que ninguém referencia não se acumula como documentação morta.
 */
final class OpenApiSpecSchemasTest extends TestCase
{
    public function testEverySchemaReferenceResolves(): void
    {
        $spec = OpenApiSpec::get();
        $defined = array_keys($spec['components']['schemas'] ?? []);
        $referenced = $this->referencedSchemas($spec);

        self::assertNotEmpty($referenced, 'reference extraction found nothing, so this test checks nothing');
        self::assertSame(
            [],
            array_values(array_diff($referenced, $defined)),
            'schemas referenced by $ref but never defined'
        );
    }

    public function testEveryDefinedSchemaIsReferenced(): void
    {
        $spec = OpenApiSpec::get();
        $defined = array_keys($spec['components']['schemas'] ?? []);

        self::assertSame(
            [],
            array_values(array_diff($defined, $this->referencedSchemas($spec))),
            'schemas defined in components but referenced by nothing'
        );
    }

    public function testEveryOperationDocumentsAtLeastOneResponse(): void
    {
        foreach ($spec = OpenApiSpec::get()['paths'] ?? [] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                self::assertNotEmpty(
                    $operation['responses'] ?? [],
                    strtoupper((string)$method) . ' ' . $path . ' documents no response'
                );
            }
        }

        self::assertNotEmpty($spec, 'the spec documents no paths at all');
    }

    /**
     * @return list<string> schema names referenced anywhere in the document
     */
    private function referencedSchemas(array $spec): array
    {
        $json = json_encode($spec, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        preg_match_all('~#/components/schemas/([A-Za-z0-9_]+)~', $json, $matches);

        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }
}
