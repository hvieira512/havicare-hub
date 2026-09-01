<?php

declare(strict_types=1);

namespace Hub\Runtime;

use Hub\Api\Repository\ApiDataAccess;
use Hub\Dashboard\DashboardStore;
use Hub\Device\CommercialModelResolver;
use Hub\Device\DeviceHubServer;
use Hub\Device\HubMqttBridge;
use Hub\Device\PendingDownlinkQueue;
use Hub\Device\RedisPendingDownlinkQueue;
use Hub\Infrastructure\Persistence\DashboardDatabase;
use Hub\Location\LocationEnricherFactory;
use Hub\Location\LocationTelemetryEnricherContract;
use Hub\Mqtt\ConnectionFactory;
use Hub\Registry\Whitelist;
use PhpMqtt\Client\MqttClient;
use Predis\Client as RedisClient;
use Predis\ClientInterface;

/**
 * A raiz de composição dos serviços de vida longa do hub.
 *
 * Tudo aqui é um singleton do processo, partilhado por referência, incluindo uma ligação
 * Redis só: cada consumidor usa um prefixo de chaves disjunto e apenas comandos síncronos
 * simples, e por isso uma ligação serve-os a todos.
 */
final class HubServices
{
    public function __construct(
        public readonly DashboardDatabase $database,
        public readonly ApiDataAccess $dataAccess,
        public readonly ClientInterface $redis,
        public readonly Whitelist $whitelist,
        public readonly PendingDownlinkQueue $downlinkQueue,
        public readonly DashboardStore $dashboardStore,
        public readonly CommercialModelResolver $commercialModelResolver,
        public readonly HubMqttBridge $mqttBridge,
        public readonly DeviceHubServer $hubServer,
        public readonly ?LocationTelemetryEnricherContract $locationEnricher,
    ) {
    }

    /**
     * @param array<string, mixed> $config the full hub config
     */
    public static function boot(array $config, ConnectionFactory $connections): self
    {
        $database = CliBootstrap::database($config);
        $dataAccess = ApiDataAccess::fromDatabase($database);
        $redis = new RedisClient(
            self::redisParameters($config['redis']),
            self::redisOptions($config['redis']),
        );

        $whitelistFile = trim((string)$config['hub']['whitelist_file']);
        $whitelist = new Whitelist($whitelistFile !== '' ? $whitelistFile : null, $dataAccess->whitelist);

        $dashboardStore = new DashboardStore($redis, (int)$config['dashboard']['history_limit']);
        $dashboardStore->setDataAccess($dataAccess);

        $downlinkQueue = new RedisPendingDownlinkQueue($redis);
        $commercialModelResolver = new CommercialModelResolver($dataAccess->models);

        $mqttBridge = new HubMqttBridge(
            $connections->build('pub'),
            trim((string)$config['mqtt']['topic_prefix'], '/'),
            static fn (): MqttClient => $connections->build('pub'),
        );

        $locationEnricher = LocationEnricherFactory::create(
            $config['location_resolution'],
            $database->pdo(),
            $redis,
        );

        $hubServer = new DeviceHubServer(
            $whitelist,
            $mqttBridge,
            $commercialModelResolver,
            downlinkQueue: $downlinkQueue,
            dashboardStore: $dashboardStore,
            downlinkQueueTtlSeconds: (int)$config['hub']['downlink_queue_ttl_seconds'],
            locationTelemetryEnricher: $locationEnricher,
        );

        return new self(
            $database,
            $dataAccess,
            $redis,
            $whitelist,
            $downlinkQueue,
            $dashboardStore,
            $commercialModelResolver,
            $mqttBridge,
            $hubServer,
            $locationEnricher,
        );
    }

    /**
     * @param array<string, mixed> $redisConfig the `redis` section of the hub config
     *
     * @return array<string, mixed>
     */
    public static function redisParameters(array $redisConfig): array
    {
        $parameters = [
            'host' => $redisConfig['host'],
            'port' => $redisConfig['port'],
        ];

        $password = (string)($redisConfig['password'] ?? '');
        if ($password !== '') {
            $parameters['password'] = $password;
        }

        return $parameters;
    }

    /**
     * O prefixo vai no cliente e não em cada store.
     *
     * Os seis espaços de chaves do hub -- `hub:dashboard`, `hub:api-tokens`, `hub:downlink`,
     * `hub:moko` e os dois de localização -- recebem o prefixo por igual, e qualquer store
     * que venha a existir recebe-o também sem ninguém se lembrar disso. Passá-lo aos
     * construtores obrigava seis sítios a não esquecer, e o sétimo esquecia.
     *
     * Só o comandos de chave é que o hub usa -- não há `SCAN`, `KEYS`, `EVAL` nem pub/sub em
     * Redis, e o `DeviceUpdateNotifier` anuncia dentro do processo --, e por isso o
     * processador de prefixos do Predis cobre tudo o que aqui se faz.
     *
     * Vazio não se declara: um `prefix` a vazio é uma opção que o Predis passa a processar
     * para nada acrescentar.
     *
     * @param array<string, mixed> $redisConfig a secção `redis` da configuração
     *
     * @return array<string, mixed>
     */
    public static function redisOptions(array $redisConfig): array
    {
        $prefix = trim((string)($redisConfig['prefix'] ?? ''));

        return $prefix === '' ? [] : ['prefix' => $prefix];
    }
}
