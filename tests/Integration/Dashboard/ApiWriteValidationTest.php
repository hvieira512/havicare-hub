<?php

namespace Tests\Integration\Dashboard;

use GuzzleHttp\Psr7\ServerRequest;
use Tests\Support\DashboardHttpTestCase;

/**
 * O que a API recusa num corpo de escrita, e com que forma o diz.
 *
 * Os serviços que passaram a validar por constraints não tinham teste ao nível da rota: os
 * testes novos exercitam o `RequestBinder` em isolamento, e os antigos chamavam os serviços
 * directamente. Entre os dois ficava por verificar exactamente o que um cliente vê -- o
 * estado HTTP, o `code`, a `message` e o `fields` -- que é a parte que é contrato.
 *
 * A regra que estes testes trancam é a da compatibilidade: **um campo a falhar responde
 * exactamente o que respondia antes**, e o `fields` vem por acréscimo. Só quando falham
 * vários é que a mensagem passa a ser genérica, e essa é situação que a API antiga não sabia
 * produzir -- devolvia um erro de cada vez.
 */
final class ApiWriteValidationTest extends DashboardHttpTestCase
{
    /** @return array{0: callable, 1: string} */
    private function serverAndAdminToken(): array
    {
        $server = $this->makeServer();

        return [$server, $this->loginToken($server, 'admin', 'secret')];
    }

    /** @param array<string, mixed> $body */
    private function write(callable $server, string $method, string $path, string $token, array $body): array
    {
        $response = $server(new ServerRequest(
            $method,
            $path,
            ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR)
        ));

        return [
            'status' => $response->getStatusCode(),
            'body' => json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * O caso que existia antes: um campo a falhar, e a resposta é a de sempre.
     *
     * O `username` nunca teve código próprio -- é `invalid_request` como sempre foi --, mas
     * tinha mensagem, e é essa que um cliente mostra a quem preenche o formulário.
     */
    public function testASingleMissingFieldAnswersExactlyWhatItAlwaysDid(): void
    {
        [$server, $token] = $this->serverAndAdminToken();

        $result = $this->write($server, 'POST', '/api/users', $token, [
            'password' => 'uma-password-comprida',
            'role' => 'hub_admin',
        ]);

        self::assertSame(400, $result['status']);
        self::assertSame('invalid_request', $result['body']['error']['code'] ?? null);
        self::assertSame('username is required', $result['body']['error']['message'] ?? null);
        self::assertSame(['username is required'], $result['body']['error']['fields']['username'] ?? null);
    }

    /** O código próprio de um campo sobrevive, que é o que distingue este caso dos outros. */
    public function testAFieldWithItsOwnCodeKeepsIt(): void
    {
        [$server, $token] = $this->serverAndAdminToken();

        $result = $this->write($server, 'POST', '/api/users', $token, [
            'username' => 'novo',
            'password' => 'uma-password-comprida',
            'role' => 'feiticeiro',
        ]);

        self::assertSame(400, $result['status']);
        self::assertSame('invalid_role', $result['body']['error']['code'] ?? null);
        self::assertSame('role must be hub_admin or license_client', $result['body']['error']['message'] ?? null);
    }

    /** Vários campos numa resposta só: é o que a API antiga não sabia fazer. */
    public function testSeveralInvalidFieldsComeBackTogether(): void
    {
        [$server, $token] = $this->serverAndAdminToken();

        $result = $this->write($server, 'POST', '/api/users', $token, ['role' => 'feiticeiro']);

        self::assertSame(400, $result['status']);
        self::assertSame('invalid_request', $result['body']['error']['code'] ?? null);
        self::assertSame(
            ['password', 'role', 'username'],
            array_keys($result['body']['error']['fields'] ?? []),
            'três idas ao servidor passam a ser uma'
        );
    }

    /**
     * O criar de uma empresa sem nome passa a ser recusado.
     *
     * O serviço normalizava antes de verificar, e o `normalizeCompany()` nunca devolve vazio
     * -- devolve `'null'`. O `if` era código morto: isto criava uma empresa chamada `null` e
     * respondia sucesso.
     */
    public function testCreatingACompanyWithoutANameIsRejected(): void
    {
        [$server, $token] = $this->serverAndAdminToken();

        foreach ([[], ['name' => ''], ['name' => '   ']] as $body) {
            $result = $this->write($server, 'POST', '/api/companies', $token, $body);

            self::assertSame(400, $result['status'], json_encode($body));
            self::assertSame('invalid_request', $result['body']['error']['code'] ?? null);
            self::assertSame('name is required', $result['body']['error']['message'] ?? null);
        }

        $listed = $server(new ServerRequest('GET', '/api/companies', ['Authorization' => 'Bearer ' . $token]));
        $names = array_map(
            static fn(array $company): string => (string)$company['name'],
            json_decode((string)$listed->getBody(), true, 512, JSON_THROW_ON_ERROR)['data'] ?? []
        );
        self::assertNotContains('null', $names, 'nenhuma empresa chamada `null` foi criada pelo caminho');
    }

    /**
     * O criar de uma empresa repetida é idempotente, e **não** responde 409.
     *
     * Isto documenta o que a API faz, não o que a especificação diz. O
     * `CompanyRepository::create()` devolve o id da linha que já existe em vez de zero, e por
     * isso o `if ($id <= 0) return duplicateCompany()` do serviço é o segundo `if` morto
     * deste ficheiro -- o `ApiError::duplicateCompany()` tem esse único chamador e nunca
     * chega a ser construído. O `TenancyPaths` declara `duplicate` para esta rota, e ela
     * nunca o envia.
     *
     * Fica assim de propósito: passar a responder 409 é uma alteração de contrato para quem
     * conta com a idempotência, e essa decisão não é de quem escreve o teste. O que o teste
     * faz é impedir que a divergência volte a ser invisível.
     */
    public function testCreatingACompanyThatAlreadyExistsIsIdempotent(): void
    {
        [$server, $token] = $this->serverAndAdminToken();

        $first = $this->write($server, 'POST', '/api/companies', $token, ['name' => 'hitcare']);
        self::assertSame(200, $first['status'], json_encode($first['body']));
        self::assertSame('ok', $first['body']['status'] ?? null);

        $again = $this->write($server, 'POST', '/api/companies', $token, ['name' => 'hitcare']);
        self::assertSame(200, $again['status']);
        self::assertSame(
            $first['body']['id'] ?? null,
            $again['body']['id'] ?? null,
            'devolve o id da que já existe, em vez de criar outra'
        );
    }

    /** O criar de uma licença exige a empresa e o número, cada um com a sua mensagem. */
    public function testCreatingALicenseRequiresItsCompanyAndNumber(): void
    {
        [$server, $token] = $this->serverAndAdminToken();

        $missingCompany = $this->write($server, 'POST', '/api/licenses', $token, ['licenseId' => 4004]);
        self::assertSame(400, $missingCompany['status']);
        self::assertSame('companyId is required', $missingCompany['body']['error']['message'] ?? null);

        $missingNumber = $this->write($server, 'POST', '/api/licenses', $token, ['companyId' => 1]);
        self::assertSame(400, $missingNumber['status']);
        self::assertSame('licenseId is required', $missingNumber['body']['error']['message'] ?? null);
    }

    /** O `licenseId` sempre foi aceite como texto, e continua a ser. */
    public function testALicenseNumberIsAcceptedAsTextAsItAlwaysWas(): void
    {
        [$server, $token] = $this->serverAndAdminToken();

        $result = $this->write($server, 'POST', '/api/licenses', $token, [
            'companyId' => 1,
            'licenseId' => '4004',
            'name' => 'texto.dev',
        ]);

        self::assertSame(200, $result['status'], json_encode($result['body']));
        self::assertSame('ok', $result['body']['status'] ?? null);
    }

    /**
     * O actualizar de uma licença herda o que o pedido não trouxer, e valida o que trouxer.
     *
     * O `companyId` a zero era escrito na mesma e a chave estrangeira rebentava a seguir: o
     * cliente levava um 500 onde lhe pertencia um 400.
     */
    public function testUpdatingALicenseInheritsWhatIsAbsentAndRejectsWhatIsInvalid(): void
    {
        [$server, $db] = $this->makeServerWithDatabase();
        $token = $this->loginToken($server, 'admin', 'secret');
        $id = (int)($db->licenses->all()[0]['id'] ?? 0);
        self::assertGreaterThan(0, $id);

        $renamed = $this->write($server, 'PUT', "/api/licenses/{$id}", $token, ['name' => 'so-o-nome']);
        self::assertSame(200, $renamed['status'], json_encode($renamed['body']));

        $kept = $db->licenses->findById($id);
        self::assertSame('so-o-nome', (string)$kept['name']);
        self::assertGreaterThan(0, (int)$kept['company_id'], 'a empresa manteve-se');

        $invalid = $this->write($server, 'PUT', "/api/licenses/{$id}", $token, ['companyId' => 0]);
        self::assertSame(400, $invalid['status'], json_encode($invalid['body']));
        self::assertSame('companyId is required', $invalid['body']['error']['message'] ?? null);
    }

    /** Um corpo com o tipo errado é recusado em vez de convertido em silêncio. */
    public function testAValueOfTheWrongTypeIsRejectedInsteadOfCoerced(): void
    {
        [$server, $token] = $this->serverAndAdminToken();

        $result = $this->write($server, 'POST', '/api/users', $token, [
            'username' => 'novo',
            'password' => 'uma-password-comprida',
            'licenseRefId' => 'nao-e-um-numero',
        ]);

        self::assertSame(400, $result['status']);
        self::assertArrayHasKey('licenseRefId', $result['body']['error']['fields'] ?? []);
    }

    /**
     * O criar e o actualizar de um dispositivo, campo a campo.
     *
     * Escrito antes de o `DeviceService` passar a objecto de pedido, e de propósito: é a
     * rota de escrita mais usada da API e a que tinha menos rede -- os testes de dispositivos
     * chamam os serviços directamente e quase não exercitam esta validação.
     */
    public function testCreatingADeviceRequiresItsIdentityFields(): void
    {
        [$server, $token] = $this->serverAndAdminToken();

        $result = $this->write($server, 'POST', '/api/devices', $token, ['licenseId' => '1001']);

        self::assertSame(400, $result['status'], json_encode($result['body']));
        self::assertSame('invalid_request', $result['body']['error']['code'] ?? null);
        self::assertSame('imei, supplier, and model are required', $result['body']['error']['message'] ?? null);
    }

    /** Um fornecedor e modelo que não existem no catálogo são 404, e não 400. */
    public function testCreatingADeviceWithAnUnknownModelIsNotFound(): void
    {
        [$server, $token] = $this->serverAndAdminToken();

        $result = $this->write($server, 'POST', '/api/devices', $token, [
            'imei' => '861265061009777',
            'supplier' => 'Inexistente',
            'model' => 'Nenhum',
        ]);

        self::assertSame(404, $result['status'], json_encode($result['body']));
        self::assertSame('model_not_found', $result['body']['error']['code'] ?? null);
    }

    /** O `licenseId` chega como texto ou como inteiro, e as duas formas sempre valeram. */
    public function testADeviceLicenseIsAcceptedAsTextOrNumber(): void
    {
        [$server, $token] = $this->serverAndAdminToken();

        foreach ([['861265061009778', '1001'], ['861265061009779', 1001]] as [$imei, $licenseId]) {
            $result = $this->write($server, 'POST', '/api/devices', $token, [
                'imei' => $imei,
                'supplier' => 'Vivistar',
                'model' => 'L08 Pro',
                'licenseId' => $licenseId,
                'company' => 'hitcare',
            ]);

            self::assertSame(201, $result['status'], gettype($licenseId) . ': ' . json_encode($result['body']));
        }
    }

    /**
     * O actualizar herda o IMEI do caminho quando o corpo não o traz.
     *
     * É o `?? $imei` do serviço, e é comportamento que os clientes usam: um `PUT` que só
     * queira mudar o fornecedor não repete o IMEI que já está no endereço.
     */
    public function testUpdatingADeviceInheritsTheImeiFromThePath(): void
    {
        [$server, $token] = $this->serverAndAdminToken();

        $result = $this->write($server, 'PUT', '/api/devices/861265061009822', $token, [
            'supplier' => 'Vivistar',
            'model' => 'L08 Pro',
            'licenseId' => '1001',
            'company' => 'hitcare',
        ]);

        self::assertSame(200, $result['status'], json_encode($result['body']));
        self::assertSame('ok', $result['body']['status'] ?? null);
    }

    /** O corpo de configurações não entra pela rota de metadados. */
    public function testUpdatingADeviceRejectsAConfigurationPayload(): void
    {
        [$server, $token] = $this->serverAndAdminToken();

        $result = $this->write($server, 'PUT', '/api/devices/861265061009822', $token, [
            'supplier' => 'Vivistar',
            'model' => 'L08 Pro',
            'configurations' => ['fall_detection' => ['enabled' => true]],
        ]);

        self::assertSame(400, $result['status']);
        self::assertSame('invalid_request', $result['body']['error']['code'] ?? null);
        self::assertSame(
            'Use /api/devices/{imei}/configurations for device configurations',
            $result['body']['error']['message'] ?? null
        );
    }

    /** A associação de dispositivo recusa os dois campos com o texto que sempre teve. */
    public function testTheDeviceAssociationKeepsItsOwnMessage(): void
    {
        $server = $this->makeServer();
        $token = $this->loginToken($server, 'tenant', 'tenant-secret');

        $result = $this->write($server, 'PATCH', '/api/devices/861265061009844/association', $token, []);

        self::assertSame(400, $result['status']);
        self::assertSame('invalid_request', $result['body']['error']['code'] ?? null);
        self::assertSame('company and licenseId are required', $result['body']['error']['message'] ?? null);
    }
}
