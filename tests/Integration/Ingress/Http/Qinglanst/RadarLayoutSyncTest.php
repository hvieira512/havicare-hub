<?php

declare(strict_types=1);

namespace Tests\Integration\Ingress\Http\Qinglanst;

use Hub\Api\Repository\RadarApiCredentialsRepository;
use Hub\Api\Repository\RadarLayoutRepository;
use Hub\Api\Repository\WhitelistRepository;
use Hub\Ingress\Http\Qinglanst\LayoutParser;
use Hub\Ingress\Http\Qinglanst\QinglanstApiClient;
use Hub\Ingress\Http\Qinglanst\QinglanstApiException;
use Hub\Ingress\Http\Qinglanst\RadarLayoutSync;
use PDO;
use React\Promise\PromiseInterface;
use Tests\Support\MysqlDashboardTestCase;

use function React\Promise\reject;
use function React\Promise\resolve;

final class RadarLayoutSyncTest extends MysqlDashboardTestCase
{
    private const CREDENTIALS = [
        'base_url' => 'https://radarconsole.com/prod-api',
        'username' => 'CBS',
        'password' => 'x',
        'app_id' => 'y',
        'app_secret' => 'z',
    ];

    private RadarLayoutRepository $layouts;
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->createDashboardDatabase()->pdo();
        $this->layouts = new RadarLayoutRepository($this->pdo);
        foreach (['594B3CCBA56B', '414D74184CBF'] as $imei) {
            $this->pdo->exec("
                INSERT INTO whitelist (imei, supplier, model, device_type, license_id)
                VALUES ('$imei', 'Qinglanst', 'RD-V1', 'radar', 2103)
            ");
        }
    }

    public function testStoresTheLayoutOfEveryRadarThatAnswers(): void
    {
        $client = $this->clientAnswering([
            '594B3CCBA56B' => $this->deviceProp(),
            '414D74184CBF' => $this->deviceProp(),
        ]);

        $result = $this->await($this->sync($client)->syncLicense(self::CREDENTIALS, [
            ['imei' => '594B3CCBA56B', 'uid' => '594B3CCBA56B'],
            ['imei' => '414D74184CBF', 'uid' => '414D74184CBF'],
        ]));

        self::assertSame(2, $result['synced']);
        self::assertSame(0, $result['skipped']);
        self::assertSame(0, $result['failed']);

        $layout = $this->layouts->findByImei('594B3CCBA56B');
        self::assertNotNull($layout);
        self::assertSame(-30, $layout['room']['x_min_dm']);
        self::assertSame(['CAMA 1', 'Porta'], array_column($layout['areas'], 'name'));
    }

    /**
     * Um login por licença e não um por radar. São quinze radares numa licença, e o
     * fabricante não distingue um login legítimo de uma tentativa de força bruta.
     */
    public function testAuthenticatesOncePerLicenceAndNotOncePerRadar(): void
    {
        $client = $this->clientAnswering([
            '594B3CCBA56B' => $this->deviceProp(),
            '414D74184CBF' => $this->deviceProp(),
        ]);

        $this->await($this->sync($client)->syncLicense(self::CREDENTIALS, [
            ['imei' => '594B3CCBA56B', 'uid' => '594B3CCBA56B'],
            ['imei' => '414D74184CBF', 'uid' => '414D74184CBF'],
        ]));

        self::assertSame(1, $client->logins);
    }

    /**
     * O `777` do fabricante diz "não conheço este aparelho", e aparece por a conta ser de
     * outra licença ou por o aparelho ter saído. Em nenhum dos casos apaga a planta que já
     * está guardada: o mapa continua a valer até alguém declarar outra.
     */
    public function testACodeThatIsNotSuccessLeavesTheStoredLayoutAlone(): void
    {
        $this->layouts->store('594B3CCBA56B', $this->storedLayout(), '{}', '2026-09-01 10:00:00');

        $client = $this->clientAnswering([
            '594B3CCBA56B' => ['code' => 777, 'msg' => 'offline'],
        ]);

        $result = $this->await($this->sync($client)->syncLicense(self::CREDENTIALS, [
            ['imei' => '594B3CCBA56B', 'uid' => '594B3CCBA56B'],
        ]));

        self::assertSame(0, $result['synced']);
        self::assertSame(1, $result['skipped']);
        self::assertSame(['777' => 1], $result['codes']);

        $layout = $this->layouts->findByImei('594B3CCBA56B');
        self::assertNotNull($layout);
        self::assertSame('2026-09-01 10:00:00', $layout['fetched_at']);
    }

    /** Um radar que rebenta não leva os seguintes atrás: a licença é percorrida até ao fim. */
    public function testOneFailingRadarDoesNotStopTheRest(): void
    {
        $client = $this->clientAnswering([
            '594B3CCBA56B' => new QinglanstApiException('Qinglanst deviceProp failed (HTTP 502)', 502),
            '414D74184CBF' => $this->deviceProp(),
        ]);

        $result = $this->await($this->sync($client)->syncLicense(self::CREDENTIALS, [
            ['imei' => '594B3CCBA56B', 'uid' => '594B3CCBA56B'],
            ['imei' => '414D74184CBF', 'uid' => '414D74184CBF'],
        ]));

        self::assertSame(1, $result['synced']);
        self::assertSame(1, $result['failed']);
        self::assertNotNull($this->layouts->findByImei('414D74184CBF'));
    }

    /** Um login que falha não é quinze falhas: a licença inteira fica por sincronizar. */
    public function testAFailedLoginSkipsTheWholeLicence(): void
    {
        $client = new class () extends QinglanstApiClient {
            public int $logins = 0;

            public function __construct()
            {
            }

            public function login(array $credentials): PromiseInterface
            {
                $this->logins++;

                return reject(new QinglanstApiException('Qinglanst login failed (HTTP 401)', 401));
            }
        };

        $result = $this->await($this->sync($client)->syncLicense(self::CREDENTIALS, [
            ['imei' => '594B3CCBA56B', 'uid' => '594B3CCBA56B'],
        ]));

        self::assertSame(0, $result['synced']);
        self::assertSame(1, $result['failed']);
        self::assertNotNull($result['error']);
        self::assertNull($this->layouts->findByImei('594B3CCBA56B'));
    }

    /** Uma resposta sem `rectangle` não é uma planta: guardá-la desenhava uma sala vazia. */
    public function testARadarWithoutARoomIsNotStored(): void
    {
        $client = $this->clientAnswering([
            '594B3CCBA56B' => ['code' => 200, 'data' => ['declare_area' => '', 'declare_area_name' => []]],
        ]);

        $result = $this->await($this->sync($client)->syncLicense(self::CREDENTIALS, [
            ['imei' => '594B3CCBA56B', 'uid' => '594B3CCBA56B'],
        ]));

        self::assertSame(0, $result['synced']);
        self::assertSame(1, $result['skipped']);
        self::assertNull($this->layouts->findByImei('594B3CCBA56B'));
    }

    /**
     * O caminho por onde a sincronização acontece de verdade: alguém carregou no botão de um
     * radar. Não há relógio nenhum atrás disto.
     */
    public function testSyncsASingleRadarOnRequest(): void
    {
        $this->giveTheLicenceCredentials();
        $client = $this->clientAnswering(['594B3CCBA56B' => $this->deviceProp()]);

        $result = $this->await($this->sync($client)->syncDevice('594B3CCBA56B'));

        self::assertSame(1, $result['synced']);
        self::assertNotNull($this->layouts->findByImei('594B3CCBA56B'));
    }

    /** Sem credenciais na licença não há a quem perguntar, e dizê-lo é melhor do que um 500. */
    public function testRefusesARadarWhoseLicenceHasNoCredentials(): void
    {
        $client = $this->clientAnswering([]);

        $result = $this->await($this->sync($client)->syncDevice('594B3CCBA56B'));

        self::assertSame('license_has_no_radar_credentials', $result['error']);
        self::assertSame(0, $client->logins);
    }

    public function testRefusesADeviceThatIsNotARadar(): void
    {
        $this->pdo->exec("
            INSERT INTO whitelist (imei, supplier, model, device_type, license_id)
            VALUES ('351266770073676', '4P Touch', 'Y6M', 'watch', 2103)
        ");
        $client = $this->clientAnswering([]);

        $result = $this->await($this->sync($client)->syncDevice('351266770073676'));

        self::assertSame('device_is_not_a_radar', $result['error']);
        self::assertSame(0, $client->logins);
    }

    /** A empresa e a licença podem já vir semeadas na base de testes, e por isso não se criam à força. */
    private function giveTheLicenceCredentials(): void
    {
        $this->pdo->exec("INSERT IGNORE INTO companies (name) VALUES ('hitcare')");
        $companyId = (int)$this->pdo->query("SELECT id FROM companies WHERE name = 'hitcare'")->fetchColumn();
        $this->pdo->exec("INSERT IGNORE INTO licenses (company_id, license_id, name) VALUES ($companyId, 2103, 'casabranca')");
        $licenseRefId = (int)$this->pdo->query("SELECT id FROM licenses WHERE license_id = 2103 LIMIT 1")->fetchColumn();
        $this->pdo->exec("
            INSERT INTO radar_api_credentials (license_ref_id, base_url, username, password, app_id, app_secret)
            VALUES ($licenseRefId, 'https://radarconsole.com/prod-api', 'CBS', 'x', 'y', 'z')
        ");
    }

    private function sync(QinglanstApiClient $client): RadarLayoutSync
    {
        return new RadarLayoutSync(
            $client,
            new LayoutParser(),
            $this->layouts,
            new RadarApiCredentialsRepository($this->pdo),
            new WhitelistRepository($this->pdo),
            static fn(): string => '2026-09-17 15:00:00',
        );
    }

    /** @param array<string, mixed> $responses uid => corpo descodificado, ou a excepção a lançar */
    private function clientAnswering(array $responses): QinglanstApiClient
    {
        return new class ($responses) extends QinglanstApiClient {
            public int $logins = 0;

            /** @param array<string, mixed> $responses */
            public function __construct(private array $responses)
            {
            }

            public function login(array $credentials): PromiseInterface
            {
                $this->logins++;

                return resolve(['access_token' => 't', 'refresh_token' => 'r', 'token_type' => 'bearer', 'expires_in' => 3600]);
            }

            public function deviceProp(array $credentials, array $token, string $uid): PromiseInterface
            {
                $response = $this->responses[$uid] ?? ['code' => 777];

                return $response instanceof \Throwable ? reject($response) : resolve($response);
            }
        };
    }

    /** @return array<string, mixed> */
    private function deviceProp(): array
    {
        return ['code' => 200, 'data' => [
            'rectangle' => '{-30,-8;30,-8;-30,20;30,20}',
            'declare_area' => '{0,2,-20,-8,-11,-8,-20,12,-11,12},{4,4,-31,-8,-29,-8,-31,4,-29,4},',
            'declare_area_name' => ['0' => '2_CAMA 1', '4' => '4_Porta'],
        ]];
    }

    /** @return array<string, mixed> */
    private function storedLayout(): array
    {
        return [
            'room' => ['x_min_dm' => -1, 'y_min_dm' => -1, 'x_max_dm' => 1, 'y_max_dm' => 1],
            'areas' => [],
            'skipped' => [],
        ];
    }

    /** @template T @param PromiseInterface<T> $promise @return T */
    private function await(PromiseInterface $promise): mixed
    {
        $resolved = null;
        $failure = null;
        $promise->then(
            static function (mixed $value) use (&$resolved): void {
                $resolved = $value;
            },
            static function (mixed $error) use (&$failure): void {
                $failure = $error;
            },
        );

        if ($failure !== null) {
            throw $failure instanceof \Throwable ? $failure : new \RuntimeException((string)$failure);
        }
        self::assertNotNull($resolved, 'a promessa não resolveu de imediato');

        return $resolved;
    }
}
