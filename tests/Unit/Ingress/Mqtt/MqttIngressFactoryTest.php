<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt;

use Hub\Device\RedisPendingDownlinkQueue;
use Hub\Device\CommercialModelResolver;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Infrastructure\Persistence\Repository\DiaperSensitivityRepository;
use Hub\Infrastructure\Persistence\Repository\ProximityAlarmRepository;
use Hub\Infrastructure\Persistence\Repository\GatewayDeviceLinkRepository;
use Hub\Ingress\Mqtt\MqttIngressFactory;
use Hub\Mqtt\BrokerSettings;
use Hub\Mqtt\ConnectionFactory;
use Hub\Registry\Denylist;
use Hub\Runtime\HubServices;
use Hub\State\DeviceStore;
use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use Tests\Support\Doubles\InMemoryRedisClient;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingConnectionFactory;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/** O fornecedor desligado não abre sessão, e o gateway ligado abre uma só. */
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

        self::assertSame([], $runner->keys());
    }

    /** Dois subscritores com o mesmo id expulsam-se do broker: o gateway tem um só, e estável. */
    public function testTheGatewayIsOneSubscriptionUnderAStableClientId(): void
    {
        $config = self::config();
        $config['gateway']['enabled'] = true;
        $connections = new RecordingConnectionFactory(new BrokerSettings('mqtt.example.com', 1883, '', '', 'health-mqtt', 60, 5, 5));

        $runner = MqttIngressFactory::build($config, self::gatewayServices(), $connections, new StreamSelectLoop());

        self::assertSame(['gateway'], $runner->keys());
        self::assertSame(
            ['health-mqtt-gateway-sub'],
            array_map(static fn ($client): string => $client->getClientId(), $connections->created),
        );
        self::assertCount(1, $connections->repositories[0]->getSubscriptionsMatchingTopic('havicare-hub/null/0/gw/d48c49f7909c/raw'));
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
                'raw_history_sample_seconds' => 30,
            ],
            'qinglanst' => [
                'enabled' => false,
                'host' => 'mqtt.example.com',
                'topic_filter' => 'radar/+/+',
                'dashboard_seen_min_interval_ms' => 5000,
                'position_history_sample_ms' => 1000,
                'raw_history_sample_ms' => 30000,
            ],
            'tuya' => ['enabled' => false],
        ];
    }

    private static function settings(): BrokerSettings
    {
        return new BrokerSettings('mqtt.example.com', 1883, '', '', 'test', 60, 5, 5);
    }

    /** O que as pontes do gateway recebem ao construir-se; nenhuma o chama antes de haver mensagens. */
    private static function gatewayServices(): HubServices
    {
        $dataAccess = new \ReflectionClass(ApiDataAccess::class);
        $data = $dataAccess->newInstanceWithoutConstructor();
        $dataAccess->getProperty('gatewayDeviceLinks')->setValue(
            $data,
            (new \ReflectionClass(GatewayDeviceLinkRepository::class))->newInstanceWithoutConstructor(),
        );
        $dataAccess->getProperty('diaperSensitivity')->setValue(
            $data,
            (new \ReflectionClass(DiaperSensitivityRepository::class))->newInstanceWithoutConstructor(),
        );
        $dataAccess->getProperty('proximityAlarm')->setValue(
            $data,
            (new \ReflectionClass(ProximityAlarmRepository::class))->newInstanceWithoutConstructor(),
        );

        $redis = new InMemoryRedisClient();
        $reflection = new \ReflectionClass(HubServices::class);
        $services = $reflection->newInstanceWithoutConstructor();
        foreach (
            [
            'dataAccess' => $data,
            'redis' => $redis,
            'whitelist' => IngressFixtures::whitelist(),
            'denylist' => new Denylist(),
            'downlinkQueue' => new RedisPendingDownlinkQueue($redis),
            'deviceStore' => new DeviceStore($redis),
            'commercialModelResolver' => new CommercialModelResolver(),
            'mqttBridge' => new RecordingHubMqttBridge(),
            ] as $property => $value
        ) {
            $reflection->getProperty($property)->setValue($services, $value);
        }

        return $services;
    }

    /** Nunca é tocado: com tudo desligado, nenhum construtor de bridge chega a correr. */
    private static function services(): HubServices
    {
        $reflection = new \ReflectionClass(HubServices::class);

        return $reflection->newInstanceWithoutConstructor();
    }
}
