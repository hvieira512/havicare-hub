<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt;

use Hub\Ingress\Mqtt\MqttIngressFactory;
use Hub\Mqtt\BrokerSettings;
use Hub\Mqtt\ConnectionFactory;
use Hub\Runtime\HubServices;
use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;

/**
 * O que a fábrica decide sem falar com broker nenhum: quem entra e quem fica de fora.
 *
 * Os caminhos ligados abrem sessão MQTT no construtor -- é a circularidade que o
 * `SubscriberFactory` documenta -- e por isso só se exercitam contra um broker a sério, no
 * `tests/scenarios`. O que se prende aqui é o outro lado: um fornecedor desligado não é
 * registado, e as chaves de configuração que decidem isso são as certas.
 */
final class MqttIngressFactoryTest extends TestCase
{
    public function testNothingIsRegisteredWhenEverySupplierIsOff(): void
    {
        $runner = MqttIngressFactory::build(
            self::config(),
            self::services(),
            new ConnectionFactory(self::settings()),
            new StreamSelectLoop(),
        );

        self::assertSame([], $runner->names());
        self::assertSame([], $runner->keys());
    }

    /**
     * O interruptor do MOKO comanda duas ingestões e não uma.
     *
     * As pulseiras Veepoo chegam pelo mesmo gateway e pelo mesmo espaço de tópicos, e por isso
     * partilham o interruptor. Desligar o MOKO tem de as desligar às duas -- ficarem meio
     * ligadas era uma subscrição sem ninguém a alimentá-la.
     */
    public function testTheMokoSwitchAlsoGovernsTheVeepooIngress(): void
    {
        $config = self::config();
        $config['gateway']['enabled'] = false;

        $runner = MqttIngressFactory::build(
            $config,
            self::services(),
            new ConnectionFactory(self::settings()),
            new StreamSelectLoop(),
        );

        self::assertNotContains('veepoo', $runner->keys());
        self::assertNotContains('gateway', $runner->keys());
    }

    /** @return array<string, mixed> */
    private static function config(): array
    {
        return [
            'ncs' => ['enabled' => false, 'topic_filter' => '/voerka/#'],
            'gateway' => [
                'enabled' => false,
                'topic_filter' => 'havicare-hub/null/0/gw/+/raw',
                'dedupe_ttl_seconds' => 5,
                'telemetry_refresh_seconds' => 60,
                'idle_timeout_seconds' => 180,
                'raw_history_sample_seconds' => 30,
            ],
            'qinglanst' => [
                'enabled' => false,
                'host' => 'mqtt.example.com',
                'topic_filter' => 'radar/+/+',
                'stats_flush_seconds' => 300,
                'dashboard_seen_min_interval_ms' => 5000,
                'position_history_sample_ms' => 1000,
                'raw_history_sample_ms' => 30000,
            ],
        ];
    }

    private static function settings(): BrokerSettings
    {
        return new BrokerSettings('mqtt.example.com', 1883, '', '', 'test', 60, 5, 5);
    }

    /** Nunca é tocado: com tudo desligado, nenhum construtor de bridge chega a correr. */
    private static function services(): HubServices
    {
        $reflection = new \ReflectionClass(HubServices::class);

        return $reflection->newInstanceWithoutConstructor();
    }
}
