<?php

namespace Tests\Integration\Dashboard;

use GuzzleHttp\Psr7\ServerRequest;
use Tests\Support\DashboardHttpTestCase;

/**
 * O que cada cliente vê e pode fazer: o âmbito por licença, o que o perfil permite, e as
 * associações de dispositivos.
 *
 * É a parte da API onde um engano não dá erro nenhum -- dá os dados de outra pessoa --, e por
 * isso os casos negativos contam tanto como os positivos.
 */
final class DashboardApiTenancyTest extends DashboardHttpTestCase
{
    public function testCreateDeviceReturnsConflictWhenImeiAlreadyExists(): void
    {
        $server = $this->makeServer();
        $token = $this->loginToken($server, 'admin', 'secret');

        $response = $server(new ServerRequest(
            'POST',
            '/api/devices',
            ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            json_encode([
                'imei' => '861265061009822',
                'supplier' => 'Vivistar',
                'model' => 'L08 Pro',
                'deviceType' => 'watch',
                'licenseId' => '0',
            ], JSON_THROW_ON_ERROR)
        ));
        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(409, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('device_exists', $body['error']['code'] ?? null);
    }

    /**
     * O 201 do criar, nas três rotas que a especificação o promete.
     *
     * O estado de sucesso é o único argumento do `result()` que ninguém verificava: havia uma
     * asserção de 201 em toda a suite, e era num teste unitário do `ApiError`. Bastava o 201
     * cair para dentro dos parênteses da chamada ao serviço -- que é onde ele *parece* estar,
     * num ternário de várias linhas -- e as três rotas passavam a responder 200 com tudo a
     * continuar verde. É o mesmo engano silencioso do estado de erro, do lado do sucesso.
     */
    public function testTheCreateRoutesAnswerTwoHundredAndOne(): void
    {
        $server = $this->makeServer();
        $token = $this->loginToken($server, 'admin', 'secret');
        $auth = ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'];

        $device = $server(new ServerRequest('POST', '/api/devices', $auth, json_encode([
            'imei' => '861265061009900',
            'supplier' => 'Vivistar',
            'model' => 'L08 Pro',
            'deviceType' => 'watch',
            'licenseId' => '1001',
            'company' => 'hitcare',
        ], JSON_THROW_ON_ERROR)));
        self::assertSame(201, $device->getStatusCode(), (string)$device->getBody());

        $user = $server(new ServerRequest('POST', '/api/users', $auth, json_encode([
            'username' => 'novo-cliente',
            'password' => 'uma-password-comprida',
            'role' => 'hub_admin',
        ], JSON_THROW_ON_ERROR)));
        self::assertSame(201, $user->getStatusCode(), (string)$user->getBody());

        // O gateway e a pulseira têm de existir antes de se poderem ligar um ao outro.
        $pair = [
            ['861265061009911', 'MOKO', 'MKGW4', 'gateway'],
            ['861265061009922', 'MOKO', 'W6B', 'bracelet'],
        ];
        foreach ($pair as [$imei, $supplier, $model, $deviceType]) {
            $created = $server(new ServerRequest('POST', '/api/devices', $auth, json_encode([
                'imei' => $imei,
                'supplier' => $supplier,
                'model' => $model,
                'deviceType' => $deviceType,
                'licenseId' => '1001',
                'company' => 'hitcare',
            ], JSON_THROW_ON_ERROR)));
            self::assertSame(201, $created->getStatusCode(), (string)$created->getBody());
        }

        $link = $server(new ServerRequest(
            'POST',
            '/api/devices/861265061009911/links/861265061009922',
            ['Authorization' => 'Bearer ' . $token]
        ));
        self::assertSame(201, $link->getStatusCode(), (string)$link->getBody());
    }

    public function testTenantClientTokenCanUseAllowedDeviceRoutes(): void
    {
        $server = $this->makeServer();

        $login = $server(new ServerRequest(
            'POST',
            '/api/auth/login',
            ['Content-Type' => 'application/json'],
            json_encode(['username' => 'tenant', 'password' => 'tenant-secret'], JSON_THROW_ON_ERROR)
        ));

        self::assertSame(200, $login->getStatusCode(), (string)$login->getBody());
        $payload = json_decode((string)$login->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('license_client', $payload['token']['role'] ?? null);
        self::assertSame(1001, $payload['token']['license_id'] ?? null);
        $token = (string)($payload['token']['access_token'] ?? '');

        $response = $server(new ServerRequest(
            'GET',
            '/api/devices/861265061009822',
            ['Authorization' => 'Bearer ' . $token]
        ));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testTenantClientTokenOnlyListsItsLicenseDevices(): void
    {
        $server = $this->makeServer();

        $payload = json_decode((string)$server(new ServerRequest(
            'POST',
            '/api/auth/login',
            ['Content-Type' => 'application/json'],
            json_encode(['username' => 'tenant', 'password' => 'tenant-secret'], JSON_THROW_ON_ERROR)
        ))->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $token = (string)($payload['token']['access_token'] ?? '');

        $response = $server(new ServerRequest(
            'GET',
            '/api/devices',
            ['Authorization' => 'Bearer ' . $token]
        ));
        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['861265061009822'], array_map(
            static fn(array $device): string => (string)$device['imei'],
            $body['data'] ?? []
        ));
    }

    /**
     * O `licenseId` chega ao registo como inteiro pela API e como texto pelo ficheiro da
     * whitelist, e o isolamento entre clientes não pode depender de qual dos dois.
     */
    public function testTenantIsolationHoldsWhateverTypeTheLicenseIdArrivesAs(): void
    {
        foreach ([['1001', '2002'], [1001, 2002]] as [$mine, $theirs]) {
            [$server, $db, $store] = $this->makeServerWithDatabase();
            $store->registerDevice('861265061009866', 'Vivistar', 'L08 Pro', 'watch', $mine, '', '', 'hitcare');
            $db->whitelist->register('861265061009866', 'Vivistar', 'L08 Pro', 'watch', $mine, '', '', 'hitcare');
            $store->registerDevice('861265061009877', 'Vivistar', 'L08 Pro', 'watch', $theirs, '', '', 'otherCare');
            $db->whitelist->register('861265061009877', 'Vivistar', 'L08 Pro', 'watch', $theirs, '', '', 'otherCare');

            $token = $this->loginToken($server, 'tenant', 'tenant-secret');
            $label = is_string($mine) ? 'string' : 'int';

            $mineResponse = $server(new ServerRequest(
                'GET',
                '/api/devices/861265061009866',
                ['Authorization' => 'Bearer ' . $token]
            ));
            self::assertSame(200, $mineResponse->getStatusCode(), "own device, {$label} licenseId");

            $theirsResponse = $server(new ServerRequest(
                'GET',
                '/api/devices/861265061009877',
                ['Authorization' => 'Bearer ' . $token]
            ));
            self::assertSame(404, $theirsResponse->getStatusCode(), "other tenant's device, {$label} licenseId");
        }
    }

    /**
     * Um `hub_admin` não tem licença, e a tabela di-lo com NULL como já dizia no
     * `license_ref_id`. O `license_client` ao lado prova que a licença a sério continua lá.
     */
    public function testAdminHasNoLicenceInTheTableAndTheClientKeepsItsOwn(): void
    {
        [$server, $db] = $this->makeServerWithDatabase();

        $rows = [];
        foreach ($db->apiUsers->all() as $user) {
            $rows[(string)$user['username']] = $user;
        }
        self::assertNull($rows['admin']['license_id']);
        self::assertNull($rows['admin']['license_ref_id']);
        self::assertSame(1001, (int)$rows['tenant']['license_id']);

        // E o token emitido a partir disso continua a dizer o mesmo.
        $admin = $this->loginPayload($server, 'admin', 'secret');
        self::assertArrayHasKey('license_id', $admin['token']);
        self::assertNull($admin['token']['license_id']);
        $tenant = $this->loginPayload($server, 'tenant', 'tenant-secret');
        self::assertSame(1001, $tenant['token']['license_id']);
    }

    public function testDeviceWithoutALicenseIsInvisibleToATenantClient(): void
    {
        [$server, $db, $store] = $this->makeServerWithDatabase();
        $store->registerDevice('861265061009888', 'Vivistar', 'L08 Pro', 'watch', 0, '', '', 'null');
        $db->whitelist->register('861265061009888', 'Vivistar', 'L08 Pro', 'watch', 0, '', '', 'null');
        $token = $this->loginToken($server, 'tenant', 'tenant-secret');

        $response = $server(new ServerRequest(
            'GET',
            '/api/devices/861265061009888',
            ['Authorization' => 'Bearer ' . $token]
        ));

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * O inverso do teste abaixo, e o que exercita de facto o âmbito por licença: mesma
     * empresa, licença diferente. O âmbito por empresa não apanha isto, e sem este teste a
     * cláusula da licença podia desaparecer inteira com todos os outros a passar.
     */
    public function testTenantClientCannotAccessAnotherLicenseWithinItsOwnCompany(): void
    {
        [$server, $db, $store] = $this->makeServerWithDatabase();
        $hitcareId = $db->companies->create('hitcare-extra-holder');
        $db->licenses->create($hitcareId, '3003', 'hitcare-second-license');

        // A mesma empresa do cliente, e uma licença que ele não tem.
        $store->registerDevice('861265061009899', 'Vivistar', 'L08 Pro', 'watch', 3003, '', '', 'hitcare');
        $db->whitelist->register('861265061009899', 'Vivistar', 'L08 Pro', 'watch', 3003, '', '', 'hitcare');

        $token = $this->loginToken($server, 'tenant', 'tenant-secret');

        $list = $server(new ServerRequest('GET', '/api/devices', ['Authorization' => 'Bearer ' . $token]));
        $imeis = array_map(
            static fn(array $device): string => (string)$device['imei'],
            json_decode((string)$list->getBody(), true, 512, JSON_THROW_ON_ERROR)['data'] ?? []
        );
        self::assertNotContains('861265061009899', $imeis, 'listing must be scoped by license, not company alone');

        $detail = $server(new ServerRequest(
            'GET',
            '/api/devices/861265061009899',
            ['Authorization' => 'Bearer ' . $token]
        ));
        self::assertSame(404, $detail->getStatusCode());
    }

    public function testTenantClientCannotAccessSameLicenseNumberFromAnotherCompany(): void
    {
        [$server, $db, $store] = $this->makeServerWithDatabase();
        $store->registerDevice('861265061009855', 'Vivistar', 'L08 Pro', 'watch', 1001, '', '', 'otherCare');
        $db->whitelist->register('861265061009822', 'Vivistar', 'L08 Pro', 'watch', 1001, '', '', 'hitcare');
        $db->whitelist->register('861265061009855', 'Vivistar', 'L08 Pro', 'watch', 1001, '', '', 'otherCare');
        $token = $this->loginToken($server, 'tenant', 'tenant-secret');

        $list = $server(new ServerRequest('GET', '/api/devices', ['Authorization' => 'Bearer ' . $token]));
        $payload = json_decode((string)$list->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['861265061009822'], array_map(
            static fn(array $device): string => (string)$device['imei'],
            $payload['data'] ?? []
        ));

        $detail = $server(new ServerRequest(
            'GET',
            '/api/devices/861265061009855',
            ['Authorization' => 'Bearer ' . $token]
        ));
        self::assertSame(404, $detail->getStatusCode());
    }

    public function testAdminCanFilterDevicesByCompany(): void
    {
        $server = $this->makeServer();
        $token = $this->loginToken($server, 'admin', 'secret');

        $response = $server(new ServerRequest(
            'GET',
            '/api/devices?company=hitcare',
            ['Authorization' => 'Bearer ' . $token]
        ));
        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('hitcare', $body['filters']['applied']['company'] ?? null);
        self::assertSame(['861265061009822'], array_map(
            static fn(array $device): string => (string)$device['imei'],
            $body['data'] ?? []
        ));
    }

    public function testTenantClientTokenCannotReadOtherLicenseDevice(): void
    {
        $server = $this->makeServer();

        $payload = json_decode((string)$server(new ServerRequest(
            'POST',
            '/api/auth/login',
            ['Content-Type' => 'application/json'],
            json_encode(['username' => 'tenant', 'password' => 'tenant-secret'], JSON_THROW_ON_ERROR)
        ))->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $token = (string)($payload['token']['access_token'] ?? '');

        $response = $server(new ServerRequest(
            'GET',
            '/api/devices/861265061009833',
            ['Authorization' => 'Bearer ' . $token]
        ));

        self::assertSame(404, $response->getStatusCode());
    }

    public function testTenantClientTokenCannotUseAdminRoutes(): void
    {
        $server = $this->makeServer();

        $login = $server(new ServerRequest(
            'POST',
            '/api/auth/login',
            ['Content-Type' => 'application/json'],
            json_encode(['username' => 'tenant', 'password' => 'tenant-secret'], JSON_THROW_ON_ERROR)
        ));
        $payload = json_decode((string)$login->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $token = (string)($payload['token']['access_token'] ?? '');

        $response = $server(new ServerRequest(
            'DELETE',
            '/api/models/1',
            ['Authorization' => 'Bearer ' . $token]
        ));

        self::assertSame(403, $response->getStatusCode());
    }

    public function testTenantClientCanPutConfigurationButCannotUpdateDeviceMetadata(): void
    {
        [$server, $db] = $this->makeServerWithDatabase();
        $token = $this->loginToken($server, 'tenant', 'tenant-secret');

        $configResponse = $server(new ServerRequest(
            'PATCH',
            '/api/devices/861265061009822/configurations',
            ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            json_encode([
                'configurations' => [
                    'fall_detection' => ['enabled' => true],
                ],
            ], JSON_THROW_ON_ERROR)
        ));
        $configBody = json_decode((string)$configResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $configResponse->getStatusCode(), (string)$configResponse->getBody());
        self::assertSame('ok', $configBody['status'] ?? null);

        $metadataResponse = $server(new ServerRequest(
            'PUT',
            '/api/devices/861265061009822',
            ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            json_encode([
                'imei' => '861265061009822',
                'supplier' => 'Vivistar',
                'model' => 'L08 Pro',
                'licenseId' => '2002',
            ], JSON_THROW_ON_ERROR)
        ));
        $metadataBody = json_decode((string)$metadataResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(403, $metadataResponse->getStatusCode(), (string)$metadataResponse->getBody());
        self::assertSame('forbidden', $metadataBody['error']['code'] ?? null);
    }

    public function testConfigurationPatchLogsStructuredBodies(): void
    {
        [$server, $db] = $this->makeServerWithDatabase();
        $token = $this->loginToken($server, 'tenant', 'tenant-secret');
        $body = json_encode([
            'configurations' => [
                'fall_detection' => ['enabled' => true],
            ],
        ], JSON_THROW_ON_ERROR);

        $response = $server(new ServerRequest(
            'PATCH',
            '/api/devices/861265061009822/configurations',
            ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'X-Request-Id' => 'req-config-1'],
            $body
        ));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());

        $log = $this->apiLogContents();
        self::assertStringContainsString('API device configuration processed', $log);
        self::assertStringContainsString('"request_id":"req-config-1"', $log);
        self::assertStringContainsString('"request_body":' . $body, $log);
        self::assertStringContainsString('"path":"/api/devices/861265061009822/configurations"', $log);
        self::assertStringContainsString('"response_content_type":"application/json"', $log);
        self::assertStringContainsString('"response_body":{"status":"ok","results":', $log);
    }

    public function testTenantClientCanAssociateUnassignedDeviceViaPatchEndpoint(): void
    {
        $server = $this->makeServer();
        $token = $this->loginToken($server, 'tenant', 'tenant-secret');

        $response = $server(new ServerRequest(
            'PATCH',
            '/api/devices/861265061009844/association',
            ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            json_encode(['company' => 'hitcare', 'licenseId' => '1001'], JSON_THROW_ON_ERROR)
        ));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('hitcare', $body['association']['company'] ?? null);
        self::assertSame(1001, $body['association']['licenseId'] ?? null);

        $deviceResponse = $server(new ServerRequest(
            'GET',
            '/api/devices/861265061009844',
            ['Authorization' => 'Bearer ' . $token]
        ));
        $device = json_decode((string)$deviceResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $deviceResponse->getStatusCode(), (string)$deviceResponse->getBody());
        self::assertSame('hitcare', $device['device']['company'] ?? null);
        self::assertSame(1001, $device['device']['licenseId'] ?? null);
    }

    public function testTenantClientCannotAssociateAlreadyAssignedDevice(): void
    {
        $server = $this->makeServer();
        $token = $this->loginToken($server, 'tenant', 'tenant-secret');

        $response = $server(new ServerRequest(
            'PATCH',
            '/api/devices/861265061009833/association',
            ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            json_encode(['company' => 'hitcare', 'licenseId' => '1001'], JSON_THROW_ON_ERROR)
        ));

        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('device_already_associated', $body['error']['code'] ?? null);
    }

    public function testTenantClientCanDeleteItsOwnAssociation(): void
    {
        $server = $this->makeServer();
        $tenantToken = $this->loginToken($server, 'tenant', 'tenant-secret');
        $adminToken = $this->loginToken($server, 'admin', 'secret');

        $response = $server(new ServerRequest(
            'DELETE',
            '/api/devices/861265061009822/association',
            ['Authorization' => 'Bearer ' . $tenantToken]
        ));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('null', $body['association']['company'] ?? null);
        self::assertSame(0, $body['association']['licenseId'] ?? null);

        $tenantRead = $server(new ServerRequest(
            'GET',
            '/api/devices/861265061009822',
            ['Authorization' => 'Bearer ' . $tenantToken]
        ));
        self::assertSame(404, $tenantRead->getStatusCode(), (string)$tenantRead->getBody());

        $adminRead = $server(new ServerRequest(
            'GET',
            '/api/devices/861265061009822',
            ['Authorization' => 'Bearer ' . $adminToken]
        ));
        $device = json_decode((string)$adminRead->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $adminRead->getStatusCode(), (string)$adminRead->getBody());
        self::assertSame('null', $device['device']['company'] ?? null);
        self::assertSame(0, $device['device']['licenseId'] ?? null);
    }
}
