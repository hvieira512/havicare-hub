<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt;

use Hub\Ingress\Mqtt\Gateway\RedisObservationStateStore;
use Hub\Ingress\Mqtt\Moko\Bridge as MokoBridge;
use Hub\Ingress\Mqtt\Ncs\Bridge as NcsBridge;
use Hub\Ingress\Mqtt\Qinglanst\Bridge as QinglanstBridge;
use Hub\Ingress\Mqtt\Qinglanst\DashboardWritePolicy as QinglanstDashboardWritePolicy;
use Hub\Ingress\Mqtt\Qinglanst\IngestStats as QinglanstIngestStats;
use Hub\Ingress\Mqtt\Veepoo\Bridge as VeepooBridge;
use Hub\Mqtt\BrokerSettings;
use Hub\Mqtt\ConnectionFactory;
use Hub\Runtime\HubServices;
use React\EventLoop\LoopInterface;

/**
 * Monta as ingestões MQTT que a configuração liga, e entrega-as num `IngressRunner`.
 *
 * Isto vivia no `bin/server-hub.php`, onde eram quatro blocos quase iguais que valiam metade
 * do ficheiro de arranque. O que cada fornecedor precisa de saber -- que a sessão dos radares
 * tem credenciais próprias, que o MOKO e o Veepoo partilham o mesmo espaço de tópicos de
 * propósito -- é conhecimento de ingestão, e é aqui que pertence.
 */
final class MqttIngressFactory
{
    /**
     * @param array<string, mixed> $config the full hub config
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

        // Uma variável só para as duas ingestões de gateway: são o mesmo espaço de tópicos de
        // propósito, e dois cálculos separados podiam divergir sem ninguém dar por isso.
        $gatewayTopicFilter = trim((string)$config['moko']['topic_filter']);
        $runner->add('MOKO gateway ingress', self::moko($config, $services, $subscribers, $gatewayTopicFilter), 'moko');
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
                $services->dashboardStore,
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
        if (!$config['moko']['enabled']) {
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
                $services->dashboardStore,
                $services->commercialModelResolver,
                (int)$config['moko']['dedupe_ttl_seconds'],
                (int)$config['moko']['telemetry_refresh_seconds'],
                (int)$config['moko']['idle_timeout_seconds'],
                (int)$config['moko']['raw_history_sample_seconds'],
                diaperSensitivity: $services->dataAccess->diaperSensitivity,
                denylist: $services->denylist,
            ),
        );
    }

    /**
     * Pulseiras Veepoo entregues por um gateway BLE. Partilha o tópico do MOKO de propósito:
     * os gateways publicam todos em `.../gw/{mac}/raw`, e cada ingestão reclama só o que sabe
     * ler. Vai atrás do mesmo interruptor porque é o mesmo gateway que as serve.
     *
     * @param array<string, mixed> $config
     */
    private static function veepoo(
        array $config,
        HubServices $services,
        SubscriberFactory $subscribers,
        string $topicFilter,
    ): ?MqttIngress {
        if (!$config['moko']['enabled']) {
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
                // A mesma porta que trava os anúncios repetidos do MOKO trava aqui os blocos
                // que o gateway relê. Em Redis e não em memória: a releitura maior é a do
                // arranque do gateway, e um hub reiniciado teria esquecido tudo o que ela vai
                // repetir.
                new RedisObservationStateStore($services->redis),
                $topicFilter,
                $reconnect,
                $services->dashboardStore,
            ),
        );
    }

    /**
     * A ingestão dos radares abre sessão própria: outras credenciais e outro identificador de
     * cliente. O mesmo broker, ao contrário do que a documentação afirmou durante muito tempo
     * -- o que muda são os tópicos.
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
                $services->dashboardStore,
                stats: new QinglanstIngestStats(
                    $topicFilter,
                    (int)$config['qinglanst']['stats_flush_seconds'],
                ),
                dashboardWritePolicy: new QinglanstDashboardWritePolicy(
                    (int)$config['qinglanst']['dashboard_seen_min_interval_ms'],
                    (int)$config['qinglanst']['position_history_sample_ms'],
                    (int)$config['qinglanst']['raw_history_sample_ms'],
                ),
                commercialModelResolver: $services->commercialModelResolver,
                denylist: $services->denylist,
            ),
        );
    }
}
