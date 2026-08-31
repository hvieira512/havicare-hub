<?php

declare(strict_types=1);

namespace Tests\Unit\Api\Request;

use Hub\Api\Request\ApiUserWriteRequest;
use Hub\Api\Request\RequestBinder;
use PHPUnit\Framework\TestCase;

/**
 * O que o `RequestBinder` promete em relação ao `fields()` que substituiu.
 *
 * Estas rotas não tinham teste nenhum -- é por isso que a migração não partiu nada, e não
 * porque nada tenha mudado.
 */
final class RequestBinderTest extends TestCase
{
    private RequestBinder $binder;

    protected function setUp(): void
    {
        $this->binder = new RequestBinder();
    }

    /** Antes era um campo por resposta: três campos errados eram três idas ao servidor. */
    public function testEveryInvalidFieldIsReportedAtOnce(): void
    {
        $result = $this->binder->bind([], ApiUserWriteRequest::class, [ApiUserWriteRequest::GROUP_CREATE]);

        self::assertIsArray($result);
        self::assertSame('invalid_request', $result['error']['code']);
        self::assertSame(
            ['password', 'username'],
            array_keys($result['error']['fields']),
            'os dois campos em falta vêm na mesma resposta'
        );
    }

    /**
     * O `(int)"abc"` dava `0`, e o `0` quer dizer alguma coisa nas regras de licença: uma
     * entrada inválida entrava como válida.
     */
    public function testAValueOfTheWrongTypeIsRejectedInsteadOfCoerced(): void
    {
        $result = $this->binder->bind(
            ['username' => 'tenant', 'licenseRefId' => 'abc'],
            ApiUserWriteRequest::class
        );

        self::assertIsArray($result);
        self::assertSame(['must be of type ?int'], $result['error']['fields']['licenseRefId']);
    }

    /** O erro do construtor sem caminho repetia o do atributo, sem dizer de que campo era. */
    public function testATypeErrorIsReportedOnceAndNamesItsField(): void
    {
        $result = $this->binder->bind(
            ['username' => 'tenant', 'licenseRefId' => 'abc'],
            ApiUserWriteRequest::class
        );

        self::assertIsArray($result);
        self::assertSame(['licenseRefId'], array_keys($result['error']['fields']));
    }

    /** As duas grafias eram aceites com um `??` à mão por campo; agora é uma regra só. */
    public function testSnakeCaseKeysAreAcceptedForTheSameField(): void
    {
        $request = $this->binder->bind(
            ['username' => 'tenant', 'license_ref_id' => 7, 'company_id' => 3],
            ApiUserWriteRequest::class
        );

        self::assertInstanceOf(ApiUserWriteRequest::class, $request);
        self::assertSame(7, $request->licenseRefId);
        self::assertSame(3, $request->companyId);
    }

    /** Com as duas grafias no mesmo corpo ganha a que a especificação documenta. */
    public function testCamelCaseWinsOverSnakeCaseWhenBothAreSent(): void
    {
        $request = $this->binder->bind(
            ['username' => 'tenant', 'licenseRefId' => 9, 'license_ref_id' => 7],
            ApiUserWriteRequest::class
        );

        self::assertInstanceOf(ApiUserWriteRequest::class, $request);
        self::assertSame(9, $request->licenseRefId);
    }

    /** A palavra-passe só é obrigatória a criar; omiti-la a actualizar é não a mudar. */
    public function testThePasswordIsOnlyRequiredWhenCreating(): void
    {
        $created = $this->binder->bind(
            ['username' => 'tenant'],
            ApiUserWriteRequest::class,
            [ApiUserWriteRequest::GROUP_CREATE]
        );
        self::assertIsArray($created);
        self::assertArrayHasKey('password', $created['error']['fields']);

        $updated = $this->binder->bind(['username' => 'tenant'], ApiUserWriteRequest::class);
        self::assertInstanceOf(ApiUserWriteRequest::class, $updated);
    }

    /** Os valores por omissão do serviço antigo continuam a ser os mesmos. */
    public function testAnOmittedRoleStillDefaultsToLicenseClientAndEnabledToTrue(): void
    {
        $request = $this->binder->bind(['username' => 'tenant'], ApiUserWriteRequest::class);

        self::assertInstanceOf(ApiUserWriteRequest::class, $request);
        self::assertTrue($request->isLicenseClient());
        self::assertTrue($request->enabled);
    }

    /**
     * O código próprio de um campo sobrevive enquanto for ele o único a falhar.
     *
     * A validação por constraints quer devolver `invalid_request` para tudo, e isso apagava
     * o `invalid_role` que os clientes distinguem desde sempre. O serviço antigo só sabia
     * devolver um erro de cada vez, e por isso "um campo falhou" é exactamente o caso que
     * existia antes -- e nele a resposta é a de antes, com o `fields` por acréscimo.
     */
    public function testASingleFieldFailureKeepsItsOwnErrorCode(): void
    {
        $result = $this->binder->bind(
            ['username' => 'tenant', 'role' => 'wizard'],
            ApiUserWriteRequest::class,
            [],
            false,
            ['role' => 'invalid_role']
        );

        self::assertIsArray($result);
        self::assertSame('invalid_role', $result['error']['code']);
        self::assertSame('role must be hub_admin or license_client', $result['error']['message']);
        self::assertSame(['role must be hub_admin or license_client'], $result['error']['fields']['role']);
    }

    /**
     * Com vários campos a falhar não há como dois códigos viajarem numa resposta, e por isso
     * é o `invalid_request` a cobrir todos. É situação que antes não existia.
     */
    public function testSeveralFailuresFallBackToTheGenericCode(): void
    {
        $result = $this->binder->bind(
            ['role' => 'wizard'],
            ApiUserWriteRequest::class,
            [ApiUserWriteRequest::GROUP_CREATE],
            false,
            ['role' => 'invalid_role']
        );

        self::assertIsArray($result);
        self::assertSame('invalid_request', $result['error']['code']);
        self::assertSame(['password', 'role', 'username'], array_keys($result['error']['fields']));
    }

    /**
     * Num corpo JSON um `"3"` onde se espera um inteiro é um cliente com um erro. A conversão
     * de strings numéricas existe para o `multipart/form-data`, que não tem tipos, e só lá.
     */
    public function testNumericStringsAreOnlyCoercedForFormBodies(): void
    {
        $json = $this->binder->bind(
            ['username' => 'x', 'licenseRefId' => '7'],
            ApiUserWriteRequest::class
        );
        self::assertIsArray($json, 'em JSON, um inteiro em string é recusado');

        $form = $this->binder->bind(
            ['username' => 'x', 'licenseRefId' => '7'],
            ApiUserWriteRequest::class,
            [],
            true
        );
        self::assertInstanceOf(ApiUserWriteRequest::class, $form);
        self::assertSame(7, $form->licenseRefId);
    }
}
