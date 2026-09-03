<?php

namespace Tests\Support;

use GuzzleHttp\Psr7\ServerRequest;
use Hub\Api\Auth\ApiTokenStore;
use Hub\Api\Auth\LoginThrottle;
use Hub\Api\Repository\ApiDataAccess;
use Hub\Dashboard\DashboardHttpServer;
use Hub\Dashboard\DashboardStore;
use Hub\Device\MessageFanout;
use Hub\Device\PendingDownlinkQueue;
use Hub\Log\Logger;
use Hub\Registry\Whitelist;
use Hub\Runtime\DashboardServerFactory;
use Tests\Support\Doubles\InMemoryRedisClient;
use Tests\Support\Doubles\IngressFixtures;

/**
 * A cadeia HTTP da dashboard montada como em produção, com o registo de API a ser escrito
 * para um ficheiro que o teste possa ler. As classes que a usam repartem um assunto cada.
 *
 * Sem `declare(strict_types=1)` de propósito: o `makeServerWithDatabase()` regista licenças
 * com o número em texto, como ele chega pelo ficheiro da whitelist, e a coerção é parte do
 * que estes testes exercitam.
 */
abstract class DashboardHttpTestCase extends MysqlDashboardTestCase
{
    protected string $whitelistPath;
    protected string $apiLogPath;
    private string|false $originalLogFile;
    private string|false $originalLogFileApi;
    private string|false $originalLogLevel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalLogFile = getenv('LOG_FILE');
        $this->originalLogFileApi = getenv('LOG_FILE_API');
        $this->originalLogLevel = getenv('LOG_LEVEL');
        $this->apiLogPath = sys_get_temp_dir() . '/hub-dashboard-api-log-' . bin2hex(random_bytes(4)) . '.log';
        putenv('LOG_FILE=' . $this->apiLogPath);
        putenv('LOG_FILE_API=' . $this->apiLogPath);
        putenv('LOG_LEVEL=info');
        Logger::reset();
        $this->whitelistPath = IngressFixtures::whitelistPath([
            '861265061009822' => ['supplier' => 'Vivistar', 'model' => 'L08 Pro', 'licenseId' => '1001', 'company' => 'hitcare'],
            '861265061009833' => ['supplier' => 'Vivistar', 'model' => 'L08 Pro', 'licenseId' => '2002', 'company' => 'otherCare'],
            '861265061009844' => ['supplier' => 'Vivistar', 'model' => 'L08 Pro', 'licenseId' => '0', 'company' => 'null'],
        ]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->apiLogPath)) {
            unlink($this->apiLogPath);
        }
        putenv($this->originalLogFile === false ? 'LOG_FILE' : 'LOG_FILE=' . $this->originalLogFile);
        putenv($this->originalLogFileApi === false ? 'LOG_FILE_API' : 'LOG_FILE_API=' . $this->originalLogFileApi);
        putenv($this->originalLogLevel === false ? 'LOG_LEVEL' : 'LOG_LEVEL=' . $this->originalLogLevel);
        Logger::reset();
        parent::tearDown();
    }

    protected function makeServer(
        bool $apiAuthRequired = true,
        int $apiTokenTtlSeconds = 3600,
        int $apiRefreshTokenTtlSeconds = 2592000
    ): callable {
        return $this->makeServerWithDatabase(
            apiAuthRequired: $apiAuthRequired,
            apiTokenTtlSeconds: $apiTokenTtlSeconds,
            apiRefreshTokenTtlSeconds: $apiRefreshTokenTtlSeconds
        )[0];
    }

    /**
     * Devolve a cadeia inteira, e não só a dashboard: o CORS e o registo do `/api/` são
     * middleware, e é a `DashboardServerFactory` que os monta -- aqui como em produção.
     *
     * O `MessageFanout` vem no quarto lugar por ser o que a ingestão usa para anunciar uma
     * publicação: um teste que queira exercitar um stream de inquilino publica através dele.
     *
     * @return array{0: callable, 1: ApiDataAccess, 2: DashboardStore, 3: MessageFanout}
     */
    protected function makeServerWithDatabase(
        ?\Hub\Device\DeviceHubServer $hub = null,
        ?PendingDownlinkQueue $queue = null,
        bool $apiAuthRequired = true,
        int $apiTokenTtlSeconds = 3600,
        int $apiRefreshTokenTtlSeconds = 2592000,
        ?MessageFanout $messages = null,
        int $maxOpenStreams = 200,
        int $maxOpenStreamsPerUser = 5,
        ?LoginThrottle $loginThrottle = null
    ): array {
        $messages ??= new MessageFanout();
        $redis = new InMemoryRedisClient();
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $hitcareId = $db->companies->create('hitcare');
        $otherCareId = $db->companies->create('otherCare');
        $hitcareLicenseRef = $db->licenses->create($hitcareId, '1001', 'hitcare-license');
        $db->licenses->create($otherCareId, '2002', 'othercare-license');
        $db->licenses->create($otherCareId, '1001', 'overlapping-license-number');
        $db->apiUsers->create('admin', password_hash('secret', PASSWORD_DEFAULT), 'hub_admin', 0, true);
        $db->apiUsers->create('tenant', password_hash('tenant-secret', PASSWORD_DEFAULT), 'license_client', '1001', true, $hitcareLicenseRef);
        $db->whitelist->register('861265061009822', 'Vivistar', 'L08 Pro', 'watch', 1001, '', '', 'hitcare');
        $db->whitelist->register('861265061009833', 'Vivistar', 'L08 Pro', 'watch', 2002, '', '', 'otherCare');
        $db->whitelist->register('861265061009844', 'Vivistar', 'L08 Pro', 'watch', 0, '', '', 'null');
        $store = new DashboardStore($redis, prefix: 'test:dashboard:http');
        $store->setDataAccess($db);
        $store->registerDevice('861265061009822', 'Vivistar', 'L08 Pro', 'watch', 1001, '', '', 'hitcare');
        $store->registerDevice('861265061009833', 'Vivistar', 'L08 Pro', 'watch', 2002, '', '', 'otherCare');
        $store->registerDevice('861265061009844', 'Vivistar', 'L08 Pro', 'watch', 0, '', '', 'null');

        if ($hub === null) {
            $hub = $this->createMock(\Hub\Device\DeviceHubServer::class);
            $hub->method('submitDownlink')->willReturn('sent');
        }

        $dashboard = new DashboardHttpServer(
            $store,
            new ApiTokenStore($redis, 'test:api-tokens'),
            new Whitelist($this->whitelistPath, $db->whitelist),
            $hub,
            $db,
            $apiAuthRequired,
            $apiTokenTtlSeconds,
            $apiRefreshTokenTtlSeconds,
            $messages,
            $maxOpenStreams,
            $maxOpenStreamsPerUser,
            $loginThrottle
        );
        $dashboard->warmUp();

        return [DashboardServerFactory::handler($dashboard), $db, $store, $messages];
    }

    protected function loginToken(callable $server, string $username, string $password): string
    {
        $payload = $this->loginPayload($server, $username, $password);

        return (string)($payload['token']['access_token'] ?? '');
    }

    /** @return array<string, mixed> */
    protected function loginPayload(callable $server, string $username, string $password): array
    {
        $login = $server(new ServerRequest(
            'POST',
            '/api/auth/login',
            ['Content-Type' => 'application/json'],
            json_encode(['username' => $username, 'password' => $password], JSON_THROW_ON_ERROR)
        ));

        self::assertSame(200, $login->getStatusCode(), (string)$login->getBody());

        return json_decode((string)$login->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    protected function apiLogContents(): string
    {
        clearstatcache(true, $this->apiLogPath);
        return is_file($this->apiLogPath) ? (string)file_get_contents($this->apiLogPath) : '';
    }
}
