<?php

namespace Tests\Support;

use GuzzleHttp\Psr7\ServerRequest;
use Hub\Api\Auth\ApiTokenStore;
use Hub\Api\Auth\LoginThrottle;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Dashboard\DashboardHttpOptions;
use Hub\Dashboard\DashboardHttpServer;
use Hub\State\DeviceStore;
use Hub\Device\MessageFanout;
use Hub\Device\RedisPendingDownlinkQueue;
use Hub\Log\Logger;
use Hub\Registry\Whitelist;
use Hub\Runtime\DashboardServerFactory;
use Tests\Support\Doubles\InMemoryRedisClient;

/** A cadeia HTTP da dashboard como em produção; sem `strict_types` porque a coerção da licença é testada. */
abstract class DashboardHttpTestCase extends MysqlDashboardTestCase
{
    /**
     * Um hash por palavra-passe durante o processo, porque o `password_hash` custa ~190ms.
     *
     * @var array<string, string>
     */
    private static array $passwordHashes = [];

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
     * A cadeia inteira, com o CORS e o registo do `/api/`, e o `MessageFanout` com que a ingestão publica.
     *
     * @return array{0: callable, 1: ApiDataAccess, 2: DeviceStore, 3: MessageFanout}
     */
    protected function makeServerWithDatabase(
        ?\Hub\Device\DeviceHubServer $hub = null,
        ?RedisPendingDownlinkQueue $queue = null,
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
        $hitcareLicenseRef = $db->licenses->create($hitcareId, 1001, 'hitcare-license');
        $db->licenses->create($otherCareId, 2002, 'othercare-license');
        $db->licenses->create($otherCareId, 1001, 'overlapping-license-number');
        $db->apiUsers->create('admin', self::passwordHash('secret'), 'hub_admin', true);
        // A licença entra pela referência: é ela que separa a 1001 da hitcare da 1001 da
        // otherCare, criada acima de propósito para o isolamento não poder passar por sorte.
        $db->apiUsers->create('tenant', self::passwordHash('tenant-secret'), 'license_client', true, $hitcareLicenseRef);
        $db->whitelist->register('861265061009822', 'Vivistar', 'L08 Pro', 'watch', 1001, '', '', 'hitcare');
        $db->whitelist->register('861265061009833', 'Vivistar', 'L08 Pro', 'watch', 2002, '', '', 'otherCare');
        $db->whitelist->register('861265061009844', 'Vivistar', 'L08 Pro', 'watch', 0, '', '', 'null');
        $store = new DeviceStore($redis);
        $store->setDataAccess($db);
        $store->runtime->registerDevice('861265061009822', 'Vivistar', 'L08 Pro', 'watch', 1001, '', '', 'hitcare');
        $store->runtime->registerDevice('861265061009833', 'Vivistar', 'L08 Pro', 'watch', 2002, '', '', 'otherCare');
        $store->runtime->registerDevice('861265061009844', 'Vivistar', 'L08 Pro', 'watch', 0, '', '', 'null');

        if ($hub === null) {
            $hub = $this->createMock(\Hub\Device\DeviceHubServer::class);
            $hub->method('submitDownlink')->willReturn('sent');
        }

        $dashboard = new DashboardHttpServer(
            $store,
            new ApiTokenStore($redis),
            // Os testes escrevem na base por fora do processo: a lista relê-se a cada leitura.
            new Whitelist($db->whitelist, databaseCacheTtlSeconds: 0),
            $hub,
            $db,
            new DashboardHttpOptions(
                apiAuthRequired: $apiAuthRequired,
                apiTokenTtlSeconds: $apiTokenTtlSeconds,
                apiRefreshTokenTtlSeconds: $apiRefreshTokenTtlSeconds,
                maxOpenStreams: $maxOpenStreams,
                maxOpenStreamsPerUser: $maxOpenStreamsPerUser,
            ),
            $messages,
            $loginThrottle
        );
        $dashboard->warmUp();

        return [DashboardServerFactory::handler($dashboard), $db, $store, $messages];
    }

    private static function passwordHash(string $password): string
    {
        return self::$passwordHashes[$password] ??= password_hash($password, PASSWORD_DEFAULT);
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
