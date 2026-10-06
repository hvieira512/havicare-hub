<?php

declare(strict_types=1);

namespace Tests\Integration\Dashboard;

use GuzzleHttp\Psr7\ServerRequest;
use Tests\Support\DashboardHttpTestCase;

/**
 * Um campo a falhar responde o código e a mensagem de sempre, com o `fields` por acréscimo; só
 * com vários é que a mensagem passa a genérica.
 */
final class ApiWriteValidationTest extends DashboardHttpTestCase
{
    /** @return array{0: callable, 1: string} */
    private function serverAndAdminToken(): array
    {
        $server = $this->makeServer();

        return [$server, $this->loginToken($server, 'admin', 'secret')];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
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
     * O `username` não tem código próprio, mas tem a mensagem que um cliente mostra a quem
     * preenche o formulário.
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

    /** O `normalizeCompany()` nunca devolve vazio, devolve `'null'`: o vazio verifica-se antes. */
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
     * O repositório devolve o id da linha que já existe em vez de zero, e por isso o conflito
     * tem de ser detectado por outra via que não um `$id <= 0`.
     */
    public function testCreatingACompanyThatAlreadyExistsIsAConflict(): void
    {
        [$server, $token] = $this->serverAndAdminToken();

        $result = $this->write($server, 'POST', '/api/companies', $token, ['name' => 'hitcare']);

        self::assertSame(409, $result['status'], json_encode($result['body']));
        self::assertSame('duplicate', $result['body']['error']['code'] ?? null);
    }

    /** O nome normaliza-se antes de se comparar: `HITCARE` é a mesma empresa que `hitcare`. */
    public function testTheDuplicateCheckSeesThroughCasingAndSpacing(): void
    {
        [$server, $token] = $this->serverAndAdminToken();

        foreach (['HITCARE', '  hitcare  '] as $name) {
            $result = $this->write($server, 'POST', '/api/companies', $token, ['name' => $name]);
            self::assertSame(409, $result['status'], $name . ': ' . json_encode($result['body']));
        }
    }

    /**
     * Renomear para um nome que já é de outra empresa é 409: o `companies.name` é `UNIQUE`, e a
     * excepção do PDO sairia como `server_error`.
     */
    public function testRenamingACompanyOntoAnotherIsAConflictAndNotACrash(): void
    {
        [$server, $db] = $this->makeServerWithDatabase();
        $token = $this->loginToken($server, 'admin', 'secret');
        $id = (int)($db->companies->findByName('hitcare')['id'] ?? 0);
        self::assertGreaterThan(0, $id);

        $result = $this->write($server, 'PUT', "/api/companies/{$id}", $token, ['name' => 'otherCare']);

        self::assertSame(409, $result['status'], json_encode($result['body']));
        self::assertSame('duplicate', $result['body']['error']['code'] ?? null);
    }

    /** Renomear para o próprio nome não é conflito consigo mesma. */
    public function testRenamingACompanyOntoItselfIsAllowed(): void
    {
        [$server, $db] = $this->makeServerWithDatabase();
        $token = $this->loginToken($server, 'admin', 'secret');
        $id = (int)($db->companies->findByName('hitcare')['id'] ?? 0);

        $result = $this->write($server, 'PUT', "/api/companies/{$id}", $token, ['name' => 'hitcare']);

        self::assertSame(200, $result['status'], json_encode($result['body']));
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

        self::assertSame(201, $result['status'], json_encode($result['body']));
        self::assertSame('ok', $result['body']['status'] ?? null);
    }

    /** Um `companyId` a zero recusa-se com 400, antes de a chave estrangeira rebentar em 500. */
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
     * A rota de escrita mais usada da API: os outros testes de dispositivos chamam os serviços
     * directamente e não passam por esta validação.
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

    /** É o `?? $imei` do serviço: um `PUT` que só mude o fornecedor não repete o IMEI do endereço. */
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
