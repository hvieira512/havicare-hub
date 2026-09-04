<?php

namespace Tests\Integration\Dashboard;

use GuzzleHttp\Psr7\ServerRequest;
use Tests\Support\DashboardHttpTestCase;

/**
 * Um administrador emite um token de inquilino sem conhecer a password dele.
 *
 * Existe para as aplicações dos clientes deixarem de precisar de uma credencial do hub
 * configurada em cada lado: quem já fala com a plataforma do inquilino pede-lhe o token, e a
 * plataforma pede-o ao hub com a conta de administrador que já tem. O que a aplicação recebe
 * é estritamente mais fraco do que aquilo com que foi pedido.
 *
 * É por isso que o sentido único importa aqui mais do que o caso feliz: a rota só serve se
 * nunca puder emitir um token igual ou mais forte do que o de quem a chama.
 */
final class DashboardApiLicenseTokenTest extends DashboardHttpTestCase
{
    /**
     * O token emitido aponta à licença nomeada, e não a outra com o mesmo número.
     *
     * O harness semeia de propósito uma `otherCare` também com a licença 1001. O âmbito de um
     * inquilino é o par empresa+licença e nunca o número sozinho, por isso um teste que só
     * verificasse o `license_id` passaria com o token errado.
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

        // O nome sai do par, e não de quem emitiu. O tecto de streams simultâneos conta por
        // `username`: com o nome do administrador, os inquilinos todos partilhavam um balde.
        self::assertSame('hitcare/1001', $token['username'] ?? null);

        self::assertNotSame('', (string)($token['access_token'] ?? ''));
        self::assertNotSame('', (string)($token['refresh_token'] ?? ''));
    }

    /**
     * O token emitido abre as rotas do inquilino e continua fechado às de administração.
     *
     * Sem esta metade, a rota podia estar a devolver um token de administrador com o papel
     * escrito por cima -- o papel é um campo do envelope, e é o âmbito guardado que decide.
     */
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

    /**
     * Um inquilino não emite tokens, nem para si próprio.
     *
     * O `tenant` do harness é um `license_client` da mesma licença que pediria, portanto o
     * token que receberia não lhe daria nada de novo. É recusado à mesma: o que fecha a rota
     * é o papel de quem chama, e não a comparação entre o que pede e o que já tem.
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
     * Um par que não existe é recusado, e a mensagem diz qual das duas metades falhou.
     *
     * A empresa existir com outra licença é o engano provável -- é o que acontece quando um
     * inquilino novo ainda não foi criado no hub --, e responder `license_not_found` em vez de
     * um `invalid_request` genérico poupa a quem integra a adivinhação.
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
