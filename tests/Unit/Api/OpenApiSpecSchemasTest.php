<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use Hub\Api\OpenApiSpec;
use PHPUnit\Framework\TestCase;

/**
 * The spec is assembled from separate per-domain files, so a schema and the
 * path that references it are edited far apart. This pins the two together: a
 * mistyped or deleted schema name breaks the build instead of shipping a
 * document whose $refs do not resolve, and a schema nobody references cannot
 * accumulate as dead documentation.
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
