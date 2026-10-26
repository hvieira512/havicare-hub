<?php

declare(strict_types=1);

namespace Hub\Runtime;

use Hub\Api\Services\DeviceService;
use Hub\Device\CommercialModelResolver;
use Hub\Device\DeviceHubServer;
use Hub\Device\Firmware\FirmwareUpgradeStore;
use Hub\Device\HubMqttBridge;
use Hub\Device\LifecycleEvents;
use Hub\Device\RedisPendingDownlinkQueue;
use Hub\Domain\Capability\Medication\MedicationRunout;
use Hub\Domain\Liveness;
use Hub\Infrastructure\Persistence\DashboardDatabase;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Ingress\Http\Qinglanst\LayoutParser;
use Hub\Ingress\Http\Qinglanst\QinglanstApiClient;
use Hub\Ingress\Http\Qinglanst\QinglanstCloudCommands;
use Hub\Ingress\Http\Qinglanst\QinglanstCloudDownlinks;
use Hub\Ingress\Http\Qinglanst\RadarLayoutSync;
use Hub\Ingress\Http\Qinglanst\RadarStateAbsorber;
use Hub\Location\LocationEnricherFactory;
use Hub\Location\LocationTelemetryEnricherContract;
use Hub\Mqtt\ConnectionFactory;
use Hub\Registry\Denylist;
use Hub\Registry\Whitelist;
use Hub\State\ActiveAlarms;
use Hub\State\DeviceStore;
use PhpMqtt\Client\MqttClient;
use Predis\Client as RedisClient;
use Predis\ClientInterface;
use React\EventLoop\Loop;
use React\Http\Browser;

/**
 * A raiz de composição dos serviços de vida longa do hub, singletons do processo. Uma só
 * ligação Redis serve todos: prefixos disjuntos e só comandos síncronos simples.
 */
final class HubServices
{
    public function __construct(
        public readonly DashboardDatabase $database,
        public readonly ApiDataAccess $dataAccess,
        public readonly ClientInterface $redis,
        public readonly Whitelist $whitelist,
        public readonly Denylist $denylist,
        public readonly RedisPendingDownlinkQueue $downlinkQueue,
        public readonly DeviceStore $deviceStore,
        public readonly CommercialModelResolver $commercialModelResolver,
        public readonly HubMqttBridge $mqttBridge,
        public readonly DeviceHubServer $hubServer,
        public readonly ?LocationTelemetryEnricherContract $locationEnricher,
        public readonly RadarLayoutSync $radarLayoutSync,
        public readonly QinglanstCloudDownlinks $qinglanstCloudDownlinks,
        // Uma só para a API e para a manutenção.
        public readonly DeviceService $deviceService,
    ) {
    }

    /**
     * @param array<string, mixed> $config a configuração completa do hub
     */
    public static function boot(array $config, ConnectionFactory $connections): self
    {
        $database = CliBootstrap::database($config);
        $dataAccess = ApiDataAccess::fromDatabase($database);
        $redis = new RedisClient(
            self::redisParameters($config['redis']),
            self::redisOptions($config['redis']),
        );

        $whitelist = new Whitelist($dataAccess->whitelist);
        $denylist = new Denylist($dataAccess->denylist);

        $deviceStore = new DeviceStore($redis, (int)$config['dashboard']['history_limit']);
        $deviceStore->setDataAccess($dataAccess);

        $downlinkQueue = new RedisPendingDownlinkQueue($redis);
        $commercialModelResolver = new CommercialModelResolver($dataAccess->models);

        $mqttBridge = new HubMqttBridge(
            $connections->build('pub'),
            trim((string)$config['mqtt']['topic_prefix'], '/'),
            static fn (): MqttClient => $connections->build('pub'),
            gatewayCommands: (bool)$config['gateway']['commands_enabled'],
        );

        $lifecycleEvents = new LifecycleEvents($mqttBridge, $whitelist, $commercialModelResolver);
        $deviceStore->commands->announceTo($lifecycleEvents->command(...));
        $dataAccess->deviceConfigurations->announceOutcomesTo($lifecycleEvents->configuration(...));

        $locationEnricher = LocationEnricherFactory::create(
            $config['location_resolution'],
            $database->pdo(),
            $redis,
        );

        // As configurações dos radares vão à cloud do fabricante: lê-se o radar, escreve-se, e a
        // leitura seguinte fica como o que o hub mostra.
        $qinglanstClient = new QinglanstApiClient(
            new Browser(),
            (float)$config['qinglanst']['layout_sync_timeout_seconds'],
        );
        $qinglanstCommands = new QinglanstCloudCommands();
        $radarState = new RadarStateAbsorber(
            $dataAccess->deviceConfigurations,
            $qinglanstCommands,
            $dataAccess->whitelist,
            $mqttBridge,
            $deviceStore,
        );
        $radarLayoutSync = new RadarLayoutSync(
            $qinglanstClient,
            new LayoutParser(),
            $dataAccess->radarLayouts,
            $dataAccess->radarCredentials,
            $dataAccess->whitelist,
            onProps: $radarState->absorb(...),
            areaNames: $radarState->areaNames(...),
        );
        $qinglanstCloudDownlinks = new QinglanstCloudDownlinks(
            $downlinkQueue,
            $qinglanstClient,
            $qinglanstCommands,
            static function (string $imei) use ($dataAccess): ?array {
                $device = $dataAccess->whitelist->get($imei);
                if ($device === null || ($device['device_type'] ?? '') !== 'radar') {
                    return null;
                }
                $uid = trim((string)($device['device_id'] ?? ''));

                return [
                    'uid' => $uid !== '' ? $uid : $imei,
                    'credentials' => $dataAccess->radarCredentials->findByLicenseRefId((int)($device['license_ref_id'] ?? 0)),
                ];
            },
            $radarLayoutSync->syncDevice(...),
            $deviceStore->markCommand(...),
        );

        $hubServer = new DeviceHubServer(
            $whitelist,
            $mqttBridge,
            $commercialModelResolver,
            downlinkQueue: $downlinkQueue,
            deviceStore: $deviceStore,
            downlinkQueueTtlSeconds: (int)$config['hub']['downlink_queue_ttl_seconds'],
            locationTelemetryEnricher: $locationEnricher,
            denylist: $denylist,
            firmwareUpgrades: new FirmwareUpgradeStore($redis),
            effectiveConfiguration: static fn (string $imei): array => $dataAccess->deviceConfigurations
                ->effectivePayloads($imei, [...MedicationRunout::CONFIGURATION_KEYS, ...Liveness::CONFIGURATION_KEYS]),
            // Na volta seguinte do ciclo: quem pôs na fila ainda vai gravar o registo do comando.
            downlinkQueued: static fn (string $imei) => Loop::futureTick(
                static fn () => $qinglanstCloudDownlinks->dispatchPending($imei, now: true),
            ),
            alarms: new ActiveAlarms($redis),
        );

        return new self(
            $database,
            $dataAccess,
            $redis,
            $whitelist,
            $denylist,
            $downlinkQueue,
            $deviceStore,
            $commercialModelResolver,
            $mqttBridge,
            $hubServer,
            $locationEnricher,
            $radarLayoutSync,
            $qinglanstCloudDownlinks,
            new DeviceService($deviceStore, $whitelist, $hubServer, $dataAccess),
        );
    }

    /**
     * @param array<string, mixed> $redisConfig a secção `redis` da configuração do hub
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
     * O prefixo vai no cliente e não em cada store, para um store novo o receber sozinho; o
     * processador do Predis cobre tudo o que o hub faz (sem `SCAN`, `KEYS`, `EVAL` nem pub/sub).
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
