<?php

declare(strict_types=1);

namespace Tests\Unit\Api\OpenApi;

use Hub\Api\OpenApi\SchemaFromRequest;
use Hub\Api\Request\ApiUserWriteRequest;
use Hub\Api\Request\DeviceAssociationRequest;
use PHPUnit\Framework\TestCase;

/**
 * Cada tradução de constraint para esquema, presa uma a uma.
 *
 * Uma constraint que o gerador não conheça é ignorada em silêncio: continua a validar em
 * execução, só não fica descrita no documento. Isso é aceitável desde que se saiba quais são
 * -- e é para isso que serve esta lista.
 */
final class SchemaFromRequestTest extends TestCase
{
    public function testNotBlankBecomesRequired(): void
    {
        $schema = SchemaFromRequest::schema(ApiUserWriteRequest::class, [ApiUserWriteRequest::GROUP_CREATE]);

        self::assertSame(['username', 'password'], $schema['required']);
    }

    /**
     * Obrigatório não é só o `NotBlank`.
     *
     * O `licenseId` da associação é obrigatório porque o `Positive` recusa o `0` com que
     * nasce, e não porque alguém lhe tenha posto um `NotBlank`. A versão anterior deste
     * gerador procurava classes de constraint por nome e documentava-o como opcional.
     */
    public function testAnyConstraintThatRejectsTheDefaultMakesTheFieldRequired(): void
    {
        $schema = SchemaFromRequest::schema(DeviceAssociationRequest::class);

        self::assertSame(['company', 'licenseId'], $schema['required']);
    }

    /** O valor com que um campo obrigatório nasce é o que as regras recusam. */
    public function testARequiredFieldDoesNotAdvertiseItsConstructorDefault(): void
    {
        $properties = SchemaFromRequest::schema(DeviceAssociationRequest::class)['properties'];

        self::assertArrayNotHasKey('default', $properties['licenseId'], 'o 0 é recusado pelo Positive');
        self::assertSame(1, $properties['licenseId']['minimum']);
    }

    /** A mesma declaração descreve os dois corpos, conforme o grupo que a rota corre. */
    public function testAGroupedConstraintOnlyAppliesToTheSchemaThatRunsIt(): void
    {
        $update = SchemaFromRequest::schema(ApiUserWriteRequest::class);

        self::assertSame(['username'], $update['required'], 'a password não é obrigatória a actualizar');
    }

    /** O enum vem do `ApiAuthContext::roles()`, não de uma cópia na constraint. */
    public function testChoiceWithACallbackBecomesAnEnum(): void
    {
        $schema = SchemaFromRequest::schema(ApiUserWriteRequest::class);

        self::assertSame(['hub_admin', 'license_client'], $schema['properties']['role']['enum']);
    }

    public function testTypesAndNullabilityComeFromTheSignature(): void
    {
        $properties = SchemaFromRequest::schema(ApiUserWriteRequest::class)['properties'];

        self::assertSame('string', $properties['username']['type']);
        self::assertSame('integer', $properties['licenseId']['type']);
        self::assertSame('boolean', $properties['enabled']['type']);
        self::assertTrue($properties['licenseRefId']['nullable']);
        self::assertArrayNotHasKey('nullable', $properties['licenseId']);
    }

    public function testLengthAndPositiveConstraintsBecomeBounds(): void
    {
        $properties = SchemaFromRequest::schema(ApiUserWriteRequest::class)['properties'];

        self::assertSame(191, $properties['username']['maxLength']);
        self::assertSame(1, $properties['licenseRefId']['minimum'], 'Positive');
        self::assertSame(0, $properties['licenseId']['minimum'], 'PositiveOrZero');
    }

    /**
     * A string vazia é o que o construtor precisa para o campo ser opcional em PHP, não um
     * valor por omissão da API. Documentá-la dizia que omitir o campo é mandá-lo vazio, que
     * é exactamente o que o `NotBlank` recusa.
     */
    public function testConstructorArtefactsAreNotDocumentedAsDefaults(): void
    {
        $properties = SchemaFromRequest::schema(ApiUserWriteRequest::class)['properties'];

        self::assertArrayNotHasKey('default', $properties['username']);
        self::assertSame('license_client', $properties['role']['default'], 'este é um valor real');
        self::assertTrue($properties['enabled']['default']);
    }

    /** O documento servido tem de conter o que o gerador produz, e não uma cópia dele. */
    public function testTheServedSpecificationUsesTheDerivedSchema(): void
    {
        $schemas = \Hub\Api\OpenApiSpec::get()['components']['schemas'];

        self::assertSame(
            SchemaFromRequest::schema(ApiUserWriteRequest::class, [ApiUserWriteRequest::GROUP_CREATE]),
            $schemas['ApiUserCreateRequest']
        );
        self::assertSame(
            SchemaFromRequest::schema(ApiUserWriteRequest::class),
            $schemas['ApiUserUpdateRequest']
        );
    }
}
