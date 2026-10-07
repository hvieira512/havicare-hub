<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt;

use Hub\Ingress\Mqtt\Gateway\RedisObservationStateStore;
use Hub\Ingress\Mqtt\Moko\MokoBridge;
use Hub\Ingress\Mqtt\Moko\MokoGatewayOptions;
use Hub\Ingress\Mqtt\Ncs\NcsBridge;
use Hub\Ingress\Mqtt\Qinglanst\DashboardWritePolicy as QinglanstDashboardWritePolicy;
use Hub\Ingress\Mqtt\Qinglanst\IngestStats as QinglanstIngestStats;
use Hub\Ingress\Mqtt\Qinglanst\QinglanstBridge;
use Hub\Ingress\Mqtt\Veepoo\VeepooBridge;
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

        $runner->add('Qinglanst ingress', self::qinglanst($config, $services), 'qinglanst');

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
            ),
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
                stats: new QinglanstIngestStats(
                    $topicFilter,
                    (int)$config['qinglanst']['stats_flush_seconds'],
                ),
                dashboardWritePolicy: new QinglanstDashboardWritePolicy(
                    (int)$config['qinglanst']['dashboard_seen_min_interval_ms'],
                    (int)$config['qinglanst']['raw_history_sample_ms'],
                ),
                commercialModelResolver: $services->commercialModelResolver,
                denylist: $services->denylist,
                idleTimeoutSeconds: (int)$config['qinglanst']['idle_timeout_seconds'],
            ),
        );
    }
}
