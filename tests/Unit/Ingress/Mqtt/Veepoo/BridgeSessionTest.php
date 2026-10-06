<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Veepoo;

use Hub\State\DeviceStoreContract;
use Hub\Device\PendingDownlinkQueue;
use Tests\Support\Doubles\ArrayObservationStateStore;
use Hub\Ingress\Mqtt\Veepoo\VeepooBridge;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\OneShotDownlinkQueue;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/**
 * A sessão do gateway com a pulseira repete-se enquanto a ligação BLE durar, e o histórico só
 * regista «Ligado» quando ela começa mesmo.
 */
final class BridgeSessionTest extends TestCase
{
    private const GATEWAY = 'bef341903987';
    private const BRACELET = '9f69c4866e6c';
    private const TOPIC = 'havicare-hub/null/0/gw/bef341903987/raw';

    public function testRepeatingTheSessionDoesNotAnnounceAConnectionEachTime(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        for ($i = 0; $i < 4; $i++) {
            $bridge->handleReceivedMessage(self::TOPIC, self::session(true));
        }

        self::assertCount(1, self::eventsOfType($mqtt, 'device.connected'));
    }

    /**
     * A pulseira sai de alcance sempre que quem a usa se afasta, e o gateway di-lo: o ecrã
     * deixa de a mostrar ligada sem esperar pelo varrimento de aparelhos parados.
     */
    public function testLosingTheSessionAnnouncesTheDisconnection(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        $bridge->handleReceivedMessage(self::TOPIC, self::session(true));
        $bridge->handleReceivedMessage(self::TOPIC, self::session(false));

        self::assertCount(1, self::eventsOfType($mqtt, 'device.disconnected'));

        $status = array_values(array_filter(
            $mqtt->statuses,
            static fn(array $e): bool => ($e['payload']['state'] ?? null) === 'offline',
        ));
        self::assertCount(1, $status);
    }

    /** Uma sessão nova depois de a perder volta a ser um acontecimento. */
    public function testConnectingAgainIsAnnouncedAgain(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        foreach ([true, false, true] as $authenticated) {
            $bridge->handleReceivedMessage(self::TOPIC, self::session($authenticated));
        }

        self::assertCount(2, self::eventsOfType($mqtt, 'device.connected'));
    }

    /**
     * O gateway precisa do valor para saber se liga ou desliga, senão o «encontrar dispositivo» nunca
     * se pararia.
     */
    public function testTheDesiredValueTravelsWithTheCommand(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = new OneShotDownlinkQueue(
            'config:heart_rate_continuous',
            ['command' => 'config:heart_rate_continuous', 'payload' => ['enabled' => false]],
        );

        $this->bridge($mqtt, $queue)->handleReceivedMessage(self::TOPIC, self::session(true));

        self::assertCount(1, $mqtt->gatewayCommands);
        self::assertSame(
            ['enabled' => false],
            $mqtt->gatewayCommands[0]['payload']['payload'],
        );
    }

    /** O hub publica o que recebe: comparar com a anterior é conta de quem integra. */
    public function testTheFirmwareVersionIsPublishedOnEverySession(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        $bridge->handleReceivedMessage(self::TOPIC, self::session(true, '02.73.01.00-5966'));
        $bridge->handleReceivedMessage(self::TOPIC, self::session(true, '02.73.01.00-5966'));
        $bridge->handleReceivedMessage(self::TOPIC, self::session(true, '02.74.00.00-5966'));

        $versions = array_map(
            static fn(array $e): mixed => $e['payload']['data']['version'] ?? null,
            array_values(array_filter(
                $mqtt->telemetry,
                static fn(array $e): bool => ($e['payload']['type'] ?? null) === 'firmware_version',
            )),
        );

        self::assertSame([
            '02.73.01.00-5966',
            '02.73.01.00-5966',
            '02.74.00.00-5966',
        ], $versions);
    }

    /**
     * A sessão e a versão repetem-se de trinta em trinta segundos, e num histórico de cem entradas
     * expulsariam tudo o resto numa hora.
     */
    public function testTheDashboardKeepsTheFirmwareOnlyWhenItChanges(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $appended = [];
        $store = $this->createMock(DeviceStoreContract::class);
        $store->method('append')->willReturnCallback(
            static function (string $imei, string $list, array $payload) use (&$appended): void {
                $appended[] = $payload;
            }
        );
        $bridge = $this->bridge($mqtt, null, $store);

        $bridge->handleReceivedMessage(self::TOPIC, self::session(true, '02.73.01.00-5966'));
        $bridge->handleReceivedMessage(self::TOPIC, self::session(true, '02.73.01.00-5966'));
        $bridge->handleReceivedMessage(self::TOPIC, self::session(true, '02.73.01.00-5966'));
        $bridge->handleReceivedMessage(self::TOPIC, self::session(true, '02.74.00.00-5966'));

        $shown = array_values(array_map(
            static fn(array $e): mixed => $e['data']['version'] ?? null,
            array_filter($appended, static fn(array $e): bool => ($e['type'] ?? null) === 'firmware_version'),
        ));

        self::assertSame(['02.73.01.00-5966', '02.74.00.00-5966'], $shown);

        // E no MQTT continuam a sair as quatro: essa comparação é de quem integra.
        self::assertCount(4, array_filter(
            $mqtt->telemetry,
            static fn(array $e): bool => ($e['payload']['type'] ?? null) === 'firmware_version',
        ));
    }

    /** @return list<array<string, mixed>> */
    private static function eventsOfType(RecordingHubMqttBridge $mqtt, string $type): array
    {
        return array_values(array_filter(
            $mqtt->events,
            static fn(array $entry): bool => ($entry['payload']['type'] ?? null) === $type,
        ));
    }

    private static function session(bool $authenticated, ?string $firmware = null): string
    {
        return json_encode([
            'source' => 'veepoo-node',
            'kind' => 'session',
            'device' => array_filter(['mac' => self::BRACELET, 'firmware' => $firmware]),
            'payload' => ['authenticated' => $authenticated],
        ], JSON_THROW_ON_ERROR);
    }

    private function bridge(
        RecordingHubMqttBridge $mqtt,
        ?PendingDownlinkQueue $queue = null,
        ?DeviceStoreContract $store = null,
    ): VeepooBridge {
        return new VeepooBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                self::GATEWAY => IngressFixtures::device('Havicare', 'Veepoo Gateway', 'gateway'),
                self::BRACELET => IngressFixtures::device('Wonlex', 'MF91', 'bracelet'),
            ]),
            $mqtt,
            IngressFixtures::links(true),
            $queue,
            new ArrayObservationStateStore(),
            'havicare-hub/null/0/gw/+/raw',
            null,
            $store,
        );
    }
}
