<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use Hub\Api\OpenApiSpec;
use PHPUnit\Framework\TestCase;

/**
 * Os esquemas e as rotas que os referenciam editam-se em ficheiros diferentes: um `$ref` que
 * não resolve parte a build, e um esquema que ninguém referencia não se acumula.
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

    /** Um esquema que promete menos do que a resposta devolve engana quem gera tipos a partir dele. */
    public function testDetailAndErrorSchemasDeclareEveryReturnedField(): void
    {
        $schemas = OpenApiSpec::get()['components']['schemas'] ?? [];

        $detail = array_keys($schemas['DeviceDetailResponse']['properties'] ?? []);
        self::assertContains('enabledCapabilityKeys', $detail);
        self::assertContains('linkedDevices', $detail);

        $error = array_keys($schemas['ErrorResponse']['properties']['error']['properties'] ?? []);
        self::assertContains('fields', $error);
        self::assertContains('requestId', $error);
    }

    /**
     * @param array<string, mixed> $spec
     * @return list<string> nomes de esquema referenciados em qualquer ponto do documento
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
