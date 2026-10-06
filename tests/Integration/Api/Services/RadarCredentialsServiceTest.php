<?php

declare(strict_types=1);

namespace Tests\Integration\Api\Services;

use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Infrastructure\Persistence\Repository\RadarApiCredentialsRepository;
use Hub\Infrastructure\Persistence\Repository\RadarLayoutRepository;
use Hub\Infrastructure\Persistence\Repository\WhitelistRepository;
use Hub\Api\Services\RadarCredentialsService;
use Hub\Ingress\Http\Qinglanst\LayoutParser;
use Hub\Ingress\Http\Qinglanst\QinglanstApiClient;
use Hub\Ingress\Http\Qinglanst\QinglanstApiException;
use Hub\Ingress\Http\Qinglanst\RadarLayoutSync;
use PDO;
use React\Promise\PromiseInterface;
use Tests\Support\MysqlDashboardTestCase;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * As credenciais da cloud dos radares são de cada licença: com a conta de outra, os radares
 * respondem `777`, offline, mesmo quando estão a publicar.
 */
final class RadarCredentialsServiceTest extends MysqlDashboardTestCase
{
    private RadarCredentialsService $service;
    private ApiDataAccess $db;
    private PDO $pdo;
    private int $licenseRefId;

    protected function setUp(): void
    {
        parent::setUp();
        $database = $this->createDashboardDatabase();
        $this->pdo = $database->pdo();
        $this->db = ApiDataAccess::fromDatabase($database);
        $this->service = new RadarCredentialsService($this->db);

        $companyId = $this->db->companies->create('hitcare');
        $this->licenseRefId = $this->db->licenses->create($companyId, 2103, 'casabrancaresidencial');
    }

    /**
     * A palavra-passe e o segredo ficam reversíveis, porque servem para fazer login, e a defesa
     * é não os devolver: quem pergunta só precisa de saber se já lá estão.
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
     * O ecrã nunca recebe os segredos e não os pode reenviar: gravar sem eles é «fica como
     * está», e não «apaga».
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
     * A sincronização por licença escreve aqui o `access_token` do fabricante por outro caminho:
     * um token renovado a meio de uma edição não pode reescrever as credenciais.
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

    /**
     * Uma conta de outra licença autentica à mesma e só depois os radares desta respondem `777`:
     * por isso volta a contagem dos que responderam, e não um «ligou».
     */
    public function testTheConnectionCheckCountsTheRadarsThatAnswer(): void
    {
        $this->registerRadars('594B3CCBA56B', '414D74184CBF');
        $service = $this->serviceWithClient($this->clientAnswering([
            '594B3CCBA56B' => 200,
            '414D74184CBF' => 777,
        ]));

        $result = $this->await($service->check($this->licenseRefId, $this->credentials()));

        self::assertSame(2, $result['data']['radars']);
        self::assertSame(1, $result['data']['responding']);
        self::assertNull($result['data']['error']);
    }

    /** Um login que falha é uma causa só, e não uma falha por radar. */
    public function testTheConnectionCheckReportsALoginThatFails(): void
    {
        $this->registerRadars('594B3CCBA56B');
        $service = $this->serviceWithClient($this->clientAnswering([], 'Qinglanst login failed'));

        $result = $this->await($service->check($this->licenseRefId, $this->credentials()));

        self::assertSame(0, $result['data']['responding']);
        self::assertSame('Qinglanst login failed', $result['data']['error']);
    }

    /**
     * O mesmo número de licença existe em empresas diferentes, e um aparelho aponta para o par
     * número + empresa: os radares da outra empresa responderiam à pergunta errada.
     */
    public function testTheConnectionCheckOnlyTriesTheRadarsOfThisCompany(): void
    {
        $this->registerRadars('594B3CCBA56B');
        $other = $this->db->companies->create('gerpi');
        $this->db->licenses->create($other, 2103, 'lar-sao-joao');
        $this->db->whitelist->register(
            imei: 'FFFFFFFFFFFF',
            supplier: 'Qinglanst',
            model: 'RD-V1',
            deviceType: 'radar',
            company: 'gerpi',
            licenseId: 2103,
        );

        $client = $this->clientAnswering(['594B3CCBA56B' => 200, 'FFFFFFFFFFFF' => 200]);
        $result = $this->await($this->serviceWithClient($client)->check($this->licenseRefId, $this->credentials()));

        self::assertSame(1, $result['data']['radars']);
    }

    /** Sem radares não há a quem perguntar, e inventar um "ligou" seria mentir. */
    public function testTheConnectionCheckSaysWhenThereIsNothingToTry(): void
    {
        $service = $this->serviceWithClient($this->clientAnswering([]));

        $result = $this->await($service->check($this->licenseRefId, $this->credentials()));

        self::assertSame(0, $result['data']['radars']);
    }

    /**
     * O ecrã nunca recebe os segredos e por isso também não os pode reenviar: experimentar
     * depois de corrigir só o endereço tem de usar os que já lá estão.
     */
    public function testTheConnectionCheckFallsBackToTheStoredSecrets(): void
    {
        $this->service->save($this->licenseRefId, $this->credentials());
        $this->registerRadars('594B3CCBA56B');
        $client = $this->clientAnswering(['594B3CCBA56B' => 200]);

        $this->await($this->serviceWithClient($client)->check($this->licenseRefId, [
            'baseUrl' => 'https://radarconsole.com/outro-api',
            'username' => 'casabranca',
            'appId' => 'ql-casabranca',
        ]));

        self::assertSame('a-boa', $client->seenCredentials['password'] ?? null);
        self::assertSame('https://radarconsole.com/outro-api', $client->seenCredentials['base_url'] ?? null);
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

    /** @return array<string, string> */
    private function credentials(): array
    {
        return [
            'baseUrl' => 'https://radarconsole.com/prod-api',
            'username' => 'casabranca',
            'password' => 'a-boa',
            'appId' => 'ql-casabranca',
            'appSecret' => 'o-bom',
        ];
    }

    private function registerRadars(string ...$imeis): void
    {
        foreach ($imeis as $imei) {
            $this->db->whitelist->register(
                imei: $imei,
                supplier: 'Qinglanst',
                model: 'RD-V1',
                deviceType: 'radar',
                company: 'hitcare',
                licenseId: 2103,
            );
        }
    }

    private function serviceWithClient(QinglanstApiClient $client): RadarCredentialsService
    {
        $pdo = $this->pdo;

        return new RadarCredentialsService($this->db, sync: new RadarLayoutSync(
            $client,
            new LayoutParser(),
            new RadarLayoutRepository($pdo),
            new RadarApiCredentialsRepository($pdo),
            new WhitelistRepository($pdo),
            static fn(): string => '2026-09-29 15:00:00',
        ));
    }

    /** @param array<string, int> $codes imei => código do fabricante */
    private function clientAnswering(array $codes, ?string $loginError = null): QinglanstApiClient
    {
        return new class ($codes, $loginError) extends QinglanstApiClient {
            /** @var array<string, string> */
            public array $seenCredentials = [];

            /** @param array<string, int> $codes */
            public function __construct(private array $codes, private ?string $loginError)
            {
            }

            public function login(array $credentials): PromiseInterface
            {
                $this->seenCredentials = $credentials;
                if ($this->loginError !== null) {
                    return reject(new QinglanstApiException($this->loginError));
                }

                return resolve([
                    'access_token' => 't',
                    'refresh_token' => 'r',
                    'token_type' => 'bearer',
                    'expires_in' => 3600,
                ]);
            }

            public function deviceProp(array $credentials, array $token, string $uid): PromiseInterface
            {
                $code = $this->codes[$uid] ?? 777;

                return resolve($code === 200
                    ? ['code' => 200, 'data' => [
                        'rectangle' => '{-30,-8;30,-8;-30,20;30,20}',
                        'declare_area' => '',
                        'declare_area_name' => [],
                    ]]
                    : ['code' => $code]);
            }
        };
    }


    /**
     * @template T
     * @param PromiseInterface<T> $promise
     * @return T
     */
    private function await(PromiseInterface $promise): mixed
    {
        $settled = null;
        $promise->then(static function (mixed $value) use (&$settled): void {
            $settled = $value;
        });

        return $settled;
    }
}
