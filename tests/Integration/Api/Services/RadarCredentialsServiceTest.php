<?php

declare(strict_types=1);

namespace Tests\Integration\Api\Services;

use Hub\Api\Repository\ApiDataAccess;
use Hub\Api\Services\RadarCredentialsService;
use Tests\Support\MysqlDashboardTestCase;

/**
 * As credenciais da cloud do fabricante dos radares, que são de cada licença.
 *
 * Não há conta que veja a frota toda: com a conta de uma licença, os radares das outras
 * respondem `777` a dizer que o aparelho está offline mesmo quando está a publicar. É por isso
 * que isto pende da licença e não do ambiente do processo.
 */
final class RadarCredentialsServiceTest extends MysqlDashboardTestCase
{
    private RadarCredentialsService $service;
    private ApiDataAccess $db;
    private int $licenseRefId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $this->service = new RadarCredentialsService($this->db);

        $companyId = $this->db->companies->create('hitcare');
        $this->licenseRefId = $this->db->licenses->create($companyId, 2103, 'casabrancaresidencial');
    }

    /**
     * A palavra-passe e o segredo da aplicação entram e não voltam a sair. Têm de ficar
     * reversíveis -- são para fazer login --, e a linha de defesa é não os devolver a ninguém:
     * quem pergunta só precisa de saber se já lá estão.
     */
    public function testNeverGivesTheSecretsBack(): void
    {
        $this->service->save($this->licenseRefId, [
            'baseUrl' => 'https://radarconsole.com/prod-api',
            'username' => 'casabranca',
            'password' => 'nao-sai-daqui',
            'appId' => 'ql-casabranca',
            'appSecret' => 'tambem-nao',
        ]);

        $shown = $this->service->show($this->licenseRefId);

        self::assertSame('https://radarconsole.com/prod-api', $shown['data']['baseUrl']);
        self::assertSame('casabranca', $shown['data']['username']);
        self::assertSame('ql-casabranca', $shown['data']['appId']);
        self::assertTrue($shown['data']['hasPassword']);
        self::assertTrue($shown['data']['hasAppSecret']);

        $encoded = json_encode($shown);
        self::assertIsString($encoded);
        self::assertStringNotContainsString('nao-sai-daqui', $encoded);
        self::assertStringNotContainsString('tambem-nao', $encoded);
    }

    /**
     * Corolário do anterior: como o ecrã nunca recebe os segredos, também não os pode
     * reenviar. Gravar sem eles é «fica como está» e não «apaga» -- caso contrário, corrigir
     * uma gralha no endereço deixava a licença sem conseguir autenticar.
     */
    public function testKeepsTheStoredSecretsWhenTheyAreNotResent(): void
    {
        $this->service->save($this->licenseRefId, [
            'baseUrl' => 'https://radarconsole.com/prod-api',
            'username' => 'casabranca',
            'password' => 'a-boa',
            'appId' => 'ql-casabranca',
            'appSecret' => 'o-bom',
        ]);

        $this->service->save($this->licenseRefId, [
            'baseUrl' => 'https://radarconsole.com/prod-api/v2',
            'username' => 'casabranca',
            'password' => '',
            'appId' => 'ql-casabranca',
            'appSecret' => '',
        ]);

        $stored = $this->db->radarCredentials->findByLicenseRefId($this->licenseRefId);

        self::assertNotNull($stored);
        self::assertSame('https://radarconsole.com/prod-api/v2', $stored['base_url']);
        self::assertSame('a-boa', $stored['password']);
        self::assertSame('o-bom', $stored['app_secret']);
    }

    /**
     * Guardar um token novo não pode levar as credenciais atrás. A sincronização corre por
     * licença e escreve aqui o `access_token` que o fabricante devolveu; se isso passasse pelo
     * mesmo caminho do formulário, um token renovado a meio de uma edição reescrevia o que o
     * utilizador estava a escrever.
     */
    public function testStoringAFreshTokenLeavesTheCredentialsAlone(): void
    {
        $this->service->save($this->licenseRefId, [
            'baseUrl' => 'https://radarconsole.com/prod-api',
            'username' => 'casabranca',
            'password' => 'a-boa',
            'appId' => 'ql-casabranca',
            'appSecret' => 'o-bom',
        ]);

        $this->db->radarCredentials->storeToken(
            $this->licenseRefId,
            'token-novo',
            'refresh-novo',
            '2026-09-17 16:00:00',
        );

        $stored = $this->db->radarCredentials->findByLicenseRefId($this->licenseRefId);

        self::assertNotNull($stored);
        self::assertSame('token-novo', $stored['access_token']);
        self::assertSame('a-boa', $stored['password']);
        self::assertSame('https://radarconsole.com/prod-api', $stored['base_url']);
    }

    public function testRefusesALicenceThatDoesNotExist(): void
    {
        $result = $this->service->save(4040, [
            'baseUrl' => 'https://radarconsole.com/prod-api',
            'username' => 'casabranca',
            'password' => 'x',
            'appId' => 'y',
            'appSecret' => 'z',
        ]);

        self::assertSame('license_not_found', $result['error']['code'] ?? null);
    }

    /** Sem nada gravado, o ecrã tem de saber desenhar o formulário vazio e não um erro. */
    public function testShowsAnEmptyShapeWhenTheLicenceHasNoCredentialsYet(): void
    {
        $shown = $this->service->show($this->licenseRefId);

        self::assertFalse($shown['data']['configured']);
        self::assertSame('', $shown['data']['baseUrl']);
        self::assertFalse($shown['data']['hasPassword']);
    }

    /** A primeira gravação tem de trazer tudo: metade das credenciais nunca autentica. */
    public function testRefusesAFirstSaveWithoutAPassword(): void
    {
        $result = $this->service->save($this->licenseRefId, [
            'baseUrl' => 'https://radarconsole.com/prod-api',
            'username' => 'casabranca',
            'appId' => 'ql-casabranca',
            'appSecret' => 'o-bom',
        ]);

        self::assertSame('invalid_request', $result['error']['code'] ?? null);
        self::assertArrayHasKey('password', $result['error']['fields'] ?? []);
    }

    public function testForgettingTheCredentialsRemovesTheRow(): void
    {
        $this->service->save($this->licenseRefId, [
            'baseUrl' => 'https://radarconsole.com/prod-api',
            'username' => 'casabranca',
            'password' => 'a-boa',
            'appId' => 'ql-casabranca',
            'appSecret' => 'o-bom',
        ]);

        self::assertSame('ok', $this->service->delete($this->licenseRefId)['status'] ?? null);
        self::assertNull($this->db->radarCredentials->findByLicenseRefId($this->licenseRefId));
    }
}
