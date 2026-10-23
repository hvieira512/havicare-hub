<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt;

use Hub\Ingress\Mqtt\Gateway\RedisObservationStateStore;
use Hub\Ingress\Mqtt\JoyTech\BloodPressureHistoryMarks;
use Hub\Ingress\Mqtt\JoyTech\JoyTechBpBridge;
use Hub\Ingress\Mqtt\JoyTech\JoyTechOximeterBridge;
use Hub\Ingress\Mqtt\JoyTech\JoyTechThermometerBridge;
use Hub\Ingress\Mqtt\JoyTech\RecentMeasurementKeys;
use Hub\Ingress\Mqtt\Lefu\LefuTorreBridge;
use Hub\Ingress\Mqtt\Lefu\ScaleOnlineUsers;
use Hub\Ingress\Mqtt\Moko\MokoBridge;
use Hub\Ingress\Mqtt\Moko\MokoGatewayOptions;
use Hub\Ingress\Mqtt\Ncs\NcsBridge;
use Hub\Ingress\Mqtt\Qinglanst\QinglanstBridge;
use Hub\Ingress\Mqtt\Veepoo\VeepooBridge;
use Hub\Ingress\Tuya\TuyaBridge;
use Hub\Mqtt\BrokerSettings;
use Hub\Mqtt\ConnectionFactory;
use Hub\Runtime\HubServices;
use Hub\State\ActiveAlarms;
use React\EventLoop\LoopInterface;

/**
 * Monta as ingestões MQTT que a configuração liga, e entrega-as num `IngressRunner`.
 */
final class MqttIngressFactory
{
    /**
     * @param array<string, mixed> $config a configuração completa do hub
     */
    public static function build(
        array $config,
        HubServices $services,
        ConnectionFactory $connections,
        LoopInterface $loop,
    ): IngressRunner {
        $runner = new IngressRunner($loop);
        $subscribers = new SubscriberFactory($connections);

        $runner->add('NCS ingress', self::ncs($config, $services, $subscribers), 'ncs');

        $runner->add('Gateway ingress', self::gateway($config, $services, $subscribers), 'gateway');

        $runner->add('Qinglanst ingress', self::qinglanst($config, $services), 'qinglanst');
        $runner->add('Tuya ingress', self::tuya($config, $services, $loop), 'tuya');

        return $runner;
    }

    /** @param array<string, mixed> $config */
    private static function ncs(array $config, HubServices $services, SubscriberFactory $subscribers): ?MqttIngress
    {
        if (!$config['ncs']['enabled']) {
            return null;
        }

        $topicFilter = trim((string)$config['ncs']['topic_filter']);

        return $subscribers->bind(
            'ncs-sub',
            $topicFilter,
            fn ($subscriber, $reconnect) => new NcsBridge(
                $subscriber,
                $services->whitelist,
                $services->mqttBridge,
                $topicFilter,
                $reconnect,
                $services->deviceStore,
                commercialModelResolver: $services->commercialModelResolver,
                denylist: $services->denylist,
            ),
        );
    }

    /**
     * Uma sessão só para todo o `…/gw/+/raw`: o MOKO trata as suas tramas e entrega o que um
     * gateway BLE reencaminha à ponte que o reclama.
     *
     * @param array<string, mixed> $config
     */
    private static function gateway(array $config, HubServices $services, SubscriberFactory $subscribers): ?MqttIngress
    {
        if (!$config['gateway']['enabled']) {
            return null;
        }

        $topicFilter = trim((string)$config['gateway']['topic_filter']);
        $common = [
            'whitelist' => $services->whitelist,
            'mqttBridge' => $services->mqttBridge,
            'links' => $services->dataAccess->gatewayDeviceLinks,
            'deviceStore' => $services->deviceStore,
            'commercialModelResolver' => $services->commercialModelResolver,
            'denylist' => $services->denylist,
        ];
        $relays = [
            // Em Redis e não em memória: a maior releitura de blocos é no arranque do gateway, e
            // tem de sobreviver a um reinício do hub.
            new VeepooBridge(
                ...$common,
                downlinks: $services->downlinkQueue,
                state: new RedisObservationStateStore($services->redis),
                topicFilter: $topicFilter,
                alarms: new ActiveAlarms($services->redis),
            ),
            new LefuTorreBridge(
                ...$common,
                queue: $services->downlinkQueue,
                topicFilter: $topicFilter,
                scaleUsers: static fn (string $imei): ?array => $services->dataAccess->deviceConfigurations
                    ->effectivePayloads($imei, ['scale_users'])['scale_users'] ?? null,
                onlineUsers: new ScaleOnlineUsers($services->redis),
                historySeen: new RecentMeasurementKeys($services->redis, 'hub:lefu:history-seen', 500),
            ),
            new JoyTechBpBridge(
                ...$common,
                queue: $services->downlinkQueue,
                topicFilter: $topicFilter,
                marks: new BloodPressureHistoryMarks($services->redis),
            ),
            new JoyTechThermometerBridge(...$common, topicFilter: $topicFilter, seen: new RecentMeasurementKeys($services->redis)),
            new JoyTechOximeterBridge(...$common),
        ];

        return $subscribers->bind(
            'gateway-sub',
            $topicFilter,
            fn ($subscriber, $reconnect) => new MokoBridge(
                $subscriber,
                $services->whitelist,
                $services->mqttBridge,
                $services->dataAccess->gatewayDeviceLinks,
                new RedisObservationStateStore($services->redis),
                $topicFilter,
                $reconnect,
                $services->deviceStore,
                $services->commercialModelResolver,
                MokoGatewayOptions::fromConfig(
                    $config['gateway'],
                    $services->dataAccess->diaperSensitivity,
                    $services->dataAccess->proximityAlarm,
                ),
                denylist: $services->denylist,
                relays: $relays,
            ),
        );
    }

    /** @param array<string, mixed> $config */
    private static function tuya(array $config, HubServices $services, LoopInterface $loop): ?MqttIngress
    {
        if (!$config['tuya']['enabled']) {
            return null;
        }

        return new TuyaBridge(
            (string)$config['tuya']['access_id'],
            (string)$config['tuya']['access_secret'],
            (string)$config['tuya']['pulsar_url'],
            (string)$config['tuya']['subscription'],
            $services->whitelist,
            $services->mqttBridge,
            $services->deviceStore,
            $services->commercialModelResolver,
            $services->denylist,
            $loop,
            $services->redis,
        );
    }

    /**
     * Os radares abrem sessão própria no mesmo broker, com outras credenciais e outro
     * identificador de cliente.
     *
     * @param array<string, mixed> $config
     */
    private static function qinglanst(array $config, HubServices $services): ?MqttIngress
    {
        if (!$config['qinglanst']['enabled']) {
            return null;
        }

        $topicFilter = trim((string)$config['qinglanst']['topic_filter']);
        $subscribers = new SubscriberFactory(
            new ConnectionFactory(BrokerSettings::fromQinglanstConfig($config['qinglanst'])),
        );

        return $subscribers->bind(
            'sub',
            $topicFilter,
            fn ($subscriber, $reconnect) => new QinglanstBridge(
                $subscriber,
                $services->whitelist,
                $services->mqttBridge,
                $topicFilter,
                $reconnect,
                $services->deviceStore,
                seenIntervalMs: (int)$config['qinglanst']['dashboard_seen_min_interval_ms'],
                rawHistorySampleMs: (int)$config['qinglanst']['raw_history_sample_ms'],
                commercialModelResolver: $services->commercialModelResolver,
                denylist: $services->denylist,
                // ponytail: uma leitura à base por entrada ou saída de área; com cache se o ritmo subir.
                layouts: static fn (string $imei): ?array => $services->dataAccess->radarLayouts->findByImei($imei),
                onMessage: static fn (string $imei) => $services->qinglanstCloudDownlinks->dispatchPending($imei),
                alarms: new ActiveAlarms($services->redis),
            ),
        );
    }
}
