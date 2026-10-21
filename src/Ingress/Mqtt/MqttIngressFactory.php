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
use Hub\Ingress\Mqtt\Qinglanst\DashboardWritePolicy as QinglanstDashboardWritePolicy;
use Hub\Ingress\Mqtt\Qinglanst\QinglanstBridge;
use Hub\Ingress\Mqtt\Veepoo\VeepooBridge;
use Hub\Ingress\Tuya\TuyaBridge;
use Hub\Mqtt\BrokerSettings;
use Hub\Mqtt\ConnectionFactory;
use Hub\Runtime\HubServices;
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

        // As duas ingestões de gateway partilham de propósito o mesmo espaço de tópicos.
        $gatewayTopicFilter = trim((string)$config['gateway']['topic_filter']);
        $runner->add('MOKO gateway ingress', self::moko($config, $services, $subscribers, $gatewayTopicFilter), 'gateway');
        $runner->add('Veepoo bracelet ingress', self::veepoo($config, $services, $subscribers, $gatewayTopicFilter), 'veepoo');
        $runner->add('Lefu scale ingress', self::lefu($config, $services, $subscribers, $gatewayTopicFilter), 'lefu');
        $runner->add('JoyTech blood pressure ingress', self::joyTech($config, $services, $subscribers, $gatewayTopicFilter), 'joytech');
        $runner->add('JoyTech thermometer ingress', self::joyTechThermometer($config, $services, $subscribers, $gatewayTopicFilter), 'joytech-thermometer');
        $runner->add('JoyTech oximeter ingress', self::joyTechOximeter($config, $services, $subscribers, $gatewayTopicFilter), 'joytech-oximeter');

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

    /** @param array<string, mixed> $config */
    private static function moko(
        array $config,
        HubServices $services,
        SubscriberFactory $subscribers,
        string $topicFilter,
    ): ?MqttIngress {
        if (!$config['gateway']['enabled']) {
            return null;
        }

        return $subscribers->bind(
            'moko-sub',
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
                ),
                denylist: $services->denylist,
            ),
        );
    }

    /** @param array<string, mixed> $config */
    private static function lefu(
        array $config,
        HubServices $services,
        SubscriberFactory $subscribers,
        string $topicFilter,
    ): ?MqttIngress {
        if (!$config['gateway']['enabled']) {
            return null;
        }

        return $subscribers->bind(
            'lefu-sub',
            $topicFilter,
            fn ($subscriber, $reconnect) => new LefuTorreBridge(
                $subscriber,
                $services->whitelist,
                $services->mqttBridge,
                $services->dataAccess->gatewayDeviceLinks,
                $services->downlinkQueue,
                $topicFilter,
                $reconnect,
                $services->deviceStore,
                commercialModelResolver: $services->commercialModelResolver,
                denylist: $services->denylist,
                scaleUsers: static fn (string $imei): ?array => $services->dataAccess->configurationLifecycle
                    ->effectivePayloads($imei, ['scale_users'])['scale_users'] ?? null,
                onlineUsers: new ScaleOnlineUsers($services->redis),
                historySeen: new RecentMeasurementKeys($services->redis, 'hub:lefu:history-seen', 500),
            ),
        );
    }

    /** @param array<string, mixed> $config */
    private static function joyTech(
        array $config,
        HubServices $services,
        SubscriberFactory $subscribers,
        string $topicFilter,
    ): ?MqttIngress {
        if (!$config['gateway']['enabled']) {
            return null;
        }

        return $subscribers->bind(
            'joytech-sub',
            $topicFilter,
            fn ($subscriber, $reconnect) => new JoyTechBpBridge(
                $subscriber,
                $services->whitelist,
                $services->mqttBridge,
                $services->dataAccess->gatewayDeviceLinks,
                $services->downlinkQueue,
                $topicFilter,
                $reconnect,
                $services->deviceStore,
                commercialModelResolver: $services->commercialModelResolver,
                denylist: $services->denylist,
                marks: new BloodPressureHistoryMarks($services->redis),
            ),
        );
    }

    /** @param array<string, mixed> $config */
    private static function joyTechThermometer(
        array $config,
        HubServices $services,
        SubscriberFactory $subscribers,
        string $topicFilter,
    ): ?MqttIngress {
        if (!$config['gateway']['enabled']) {
            return null;
        }

        return $subscribers->bind(
            'joytech-thermometer-sub',
            $topicFilter,
            fn ($subscriber, $reconnect) => new JoyTechThermometerBridge(
                $subscriber,
                $services->whitelist,
                $services->mqttBridge,
                $services->dataAccess->gatewayDeviceLinks,
                $topicFilter,
                $reconnect,
                $services->deviceStore,
                commercialModelResolver: $services->commercialModelResolver,
                denylist: $services->denylist,
                seen: new RecentMeasurementKeys($services->redis),
            ),
        );
    }

    /** @param array<string, mixed> $config */
    private static function joyTechOximeter(
        array $config,
        HubServices $services,
        SubscriberFactory $subscribers,
        string $topicFilter,
    ): ?MqttIngress {
        if (!$config['gateway']['enabled']) {
            return null;
        }

        return $subscribers->bind(
            'joytech-oximeter-sub',
            $topicFilter,
            fn ($subscriber, $reconnect) => new JoyTechOximeterBridge(
                $subscriber,
                $services->whitelist,
                $services->mqttBridge,
                $services->dataAccess->gatewayDeviceLinks,
                $topicFilter,
                $reconnect,
                $services->deviceStore,
                commercialModelResolver: $services->commercialModelResolver,
                denylist: $services->denylist,
            ),
        );
    }

    /**
     * Pulseiras Veepoo entregues por um gateway BLE: partilham o tópico e o interruptor do MOKO,
     * porque é o mesmo gateway que as serve.
     *
     * @param array<string, mixed> $config
     */
    private static function veepoo(
        array $config,
        HubServices $services,
        SubscriberFactory $subscribers,
        string $topicFilter,
    ): ?MqttIngress {
        if (!$config['gateway']['enabled']) {
            return null;
        }

        return $subscribers->bind(
            'veepoo-sub',
            $topicFilter,
            fn ($subscriber, $reconnect) => new VeepooBridge(
                $subscriber,
                $services->whitelist,
                $services->mqttBridge,
                $services->dataAccess->gatewayDeviceLinks,
                $services->downlinkQueue,
                // Em Redis e não em memória: a maior releitura de blocos é no arranque do
                // gateway, e tem de sobreviver a um reinício do hub.
                new RedisObservationStateStore($services->redis),
                $topicFilter,
                $reconnect,
                $services->deviceStore,
                commercialModelResolver: $services->commercialModelResolver,
                denylist: $services->denylist,
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
            (int)$config['tuya']['idle_timeout_seconds'],
            $loop,
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
                dashboardWritePolicy: new QinglanstDashboardWritePolicy(
                    (int)$config['qinglanst']['dashboard_seen_min_interval_ms'],
                    (int)$config['qinglanst']['raw_history_sample_ms'],
                ),
                commercialModelResolver: $services->commercialModelResolver,
                denylist: $services->denylist,
                idleTimeoutSeconds: (int)$config['qinglanst']['idle_timeout_seconds'],
                // ponytail: uma leitura à base por entrada ou saída de área; com cache se o ritmo subir.
                layouts: static fn (string $imei): ?array => $services->dataAccess->radarLayouts->findByImei($imei),
                onMessage: static fn (string $imei) => $services->qinglanstCloudDownlinks->dispatchPending($imei),
            ),
        );
    }
}
