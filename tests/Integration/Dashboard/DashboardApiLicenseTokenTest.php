<?php

declare(strict_types=1);

namespace Tests\Integration\Dashboard;

use GuzzleHttp\Psr7\ServerRequest;
use Tests\Support\DashboardHttpTestCase;

/**
 * Um administrador emite um token de inquilino sem conhecer a password dele; a rota só serve se
 * nunca emitir um token igual ou mais forte do que o de quem a chama.
 */
final class DashboardApiLicenseTokenTest extends DashboardHttpTestCase
{
    /**
     * O harness semeia uma `otherCare` também com a licença 1001: o âmbito é o par
     * empresa+licença, e verificar só o `license_id` passaria com o token errado.
     */
    public function testAdminMintsATokenScopedToTheNamedTenant(): void
    {
        [$server, $db] = $this->makeServerWithDatabase();
        $adminToken = $this->loginToken($server, 'admin', 'secret');

        $response = $server(new ServerRequest(
            'POST',
            '/api/auth/license-token',
            ['Authorization' => 'Bearer ' . $adminToken, 'Content-Type' => 'application/json'],
            json_encode(['company' => 'hitcare', 'licenseId' => 1001], JSON_THROW_ON_ERROR)
        ));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $token = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR)['token'] ?? [];

        self::assertSame('license_client', $token['role'] ?? null);
        self::assertSame('hitcare', $token['company'] ?? null);
        self::assertSame(1001, (int)($token['license_id'] ?? 0));

        $hitcare = $db->companies->findByName('hitcare');
        $license = $db->licenses->findByCompanyAndLicense((int)$hitcare['id'], 1001);
        self::assertSame((int)$license['id'], (int)($token['license_ref_id'] ?? 0));

        // O nome sai do par, e não de quem emitiu: o tecto de streams simultâneos conta por
        // `username`, e com o do administrador os inquilinos partilhariam um balde.
        self::assertSame('hitcare/1001', $token['username'] ?? null);

        self::assertNotSame('', (string)($token['access_token'] ?? ''));
        self::assertNotSame('', (string)($token['refresh_token'] ?? ''));
    }

    /** O papel é um campo do envelope: é o âmbito guardado que decide o que o token abre. */
    public function testTheMintedTokenSeesOnlyItsTenantAndCannotAdminister(): void
    {
        $server = $this->makeServerWithDatabase()[0];
        $adminToken = $this->loginToken($server, 'admin', 'secret');

        $minted = json_decode((string)$server(new ServerRequest(
            'POST',
            '/api/auth/license-token',
            ['Authorization' => 'Bearer ' . $adminToken, 'Content-Type' => 'application/json'],
            json_encode(['company' => 'hitcare', 'licenseId' => 1001], JSON_THROW_ON_ERROR)
        ))->getBody(), true, 512, JSON_THROW_ON_ERROR)['token']['access_token'] ?? '';

        $auth = ['Authorization' => 'Bearer ' . $minted];

        $devices = $server(new ServerRequest('GET', '/api/devices', $auth));
        self::assertSame(200, $devices->getStatusCode(), (string)$devices->getBody());
        $listed = json_decode((string)$devices->getBody(), true, 512, JSON_THROW_ON_ERROR)['data'] ?? [];
        self::assertNotSame([], $listed);
        foreach ($listed as $device) {
            self::assertSame('hitcare', $device['company'] ?? null);
            self::assertSame(1001, (int)($device['licenseId'] ?? 0));
        }

        // A listagem de utilizadores é de administrador, e o `RouteAccessPolicy` só abre ao
        // `license_client` a lista fechada de rotas do inquilino.
        $users = $server(new ServerRequest('GET', '/api/users', $auth));
        self::assertSame(403, $users->getStatusCode(), (string)$users->getBody());

        // E não pode voltar a emitir: um token emitido que emitisse era escalada de privilégio
        // com um passo pelo meio.
        $again = $server(new ServerRequest(
            'POST',
            '/api/auth/license-token',
            ['Authorization' => 'Bearer ' . $minted, 'Content-Type' => 'application/json'],
            json_encode(['company' => 'hitcare', 'licenseId' => 1001], JSON_THROW_ON_ERROR)
        ));
        self::assertSame(403, $again->getStatusCode(), (string)$again->getBody());
    }

    /** A allowlist abre a rota ao papel; quem decide o aparelho é a verificação do serviço. */
    public function testATenantReadsOnlyItsOwnRadarLayout(): void
    {
        [$server, $db] = $this->makeServerWithDatabase();
        $db->whitelist->register('861265061009901', 'Qinglanst', 'RD-V1', 'radar', 1001, '', '861265061009901', 'hitcare');
        $db->whitelist->register('861265061009902', 'Qinglanst', 'RD-V1', 'radar', 2002, '', '861265061009902', 'otherCare');
        $auth = ['Authorization' => 'Bearer ' . $this->loginToken($server, 'tenant', 'tenant-secret')];

        $own = $server(new ServerRequest('GET', '/api/devices/861265061009901/radar-layout', $auth));
        self::assertSame(200, $own->getStatusCode(), (string)$own->getBody());

        $other = $server(new ServerRequest('GET', '/api/devices/861265061009902/radar-layout', $auth));
        self::assertSame(404, $other->getStatusCode(), (string)$other->getBody());
    }

    /**
     * O token que o `tenant` receberia não lhe daria nada de novo, e é recusado à mesma: o que
     * fecha a rota é o papel de quem chama.
     */
    public function testALicenseClientCannotMintAtAll(): void
    {
        $server = $this->makeServerWithDatabase()[0];
        $tenantToken = $this->loginToken($server, 'tenant', 'tenant-secret');

        $response = $server(new ServerRequest(
            'POST',
            '/api/auth/license-token',
            ['Authorization' => 'Bearer ' . $tenantToken, 'Content-Type' => 'application/json'],
            json_encode(['company' => 'hitcare', 'licenseId' => 1001], JSON_THROW_ON_ERROR)
        ));

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
    }

    /**
     * A empresa existir com outra licença é o engano provável, e `license_not_found` diz a quem
     * integra qual das duas metades falhou.
     */
    public function testAnUnknownTenantIsRefusedByTheHalfThatFailed(): void
    {
        $server = $this->makeServerWithDatabase()[0];
        $adminToken = $this->loginToken($server, 'admin', 'secret');
        $auth = ['Authorization' => 'Bearer ' . $adminToken, 'Content-Type' => 'application/json'];

        $unknownCompany = $server(new ServerRequest('POST', '/api/auth/license-token', $auth, json_encode([
            'company' => 'nao-existe',
            'licenseId' => 1001,
        ], JSON_THROW_ON_ERROR)));
        self::assertSame(404, $unknownCompany->getStatusCode(), (string)$unknownCompany->getBody());
        self::assertSame(
            'company_not_found',
            json_decode((string)$unknownCompany->getBody(), true, 512, JSON_THROW_ON_ERROR)['error']['code'] ?? null
        );

        $unknownLicense = $server(new ServerRequest('POST', '/api/auth/license-token', $auth, json_encode([
            'company' => 'hitcare',
            'licenseId' => 9999,
        ], JSON_THROW_ON_ERROR)));
        self::assertSame(404, $unknownLicense->getStatusCode(), (string)$unknownLicense->getBody());
        self::assertSame(
            'license_not_found',
            json_decode((string)$unknownLicense->getBody(), true, 512, JSON_THROW_ON_ERROR)['error']['code'] ?? null
        );
    }
}
