<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Veepoo;

use Hub\State\DeviceStoreContract;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\OneShotDownlinkQueue;
use Tests\Support\Doubles\RecordingHubMqttBridge;
use Tests\Support\Doubles\VeepooFixtures;

/** A sessão repete-se enquanto durar a ligação BLE, e o histórico só regista «Ligado» quando ela começa. */
final class BridgeSessionTest extends TestCase
{
    private const BRACELET = '9f69c4866e6c';
    private const TOPIC = 'havicare-hub/null/0/gw/bef341903987/raw';

    public function testRepeatingTheSessionDoesNotAnnounceAConnectionEachTime(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = VeepooFixtures::bridge($mqtt);

        for ($i = 0; $i < 4; $i++) {
            $bridge->handleReceivedMessage(self::TOPIC, self::session(true));
        }

        self::assertCount(1, self::eventsOfType($mqtt, 'device.connected'));
    }

    /** A pulseira sai de alcance sempre que quem a usa se afasta, e o ecrã reflete-o sem esperar pelo varrimento de aparelhos parados. */
    public function testLosingTheSessionAnnouncesTheDisconnection(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = VeepooFixtures::bridge($mqtt);

        $bridge->handleReceivedMessage(self::TOPIC, self::session(true));
        $bridge->handleReceivedMessage(self::TOPIC, self::session(false));

        self::assertCount(1, self::eventsOfType($mqtt, 'device.disconnected'));

        $status = array_values(array_filter(
            $mqtt->statuses,
            static fn(array $e): bool => ($e['payload']['state'] ?? null) === 'offline',
        ));
        self::assertCount(1, $status);
    }

    /** O gateway diz a ligação em `connected`; o `authenticated` sozinho é o de um gateway antigo. */
    public function testASessionThatIsNoLongerConnectedAnnouncesTheDisconnection(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = VeepooFixtures::bridge($mqtt);

        $bridge->handleReceivedMessage(self::TOPIC, self::session(true));
        $bridge->handleReceivedMessage(self::TOPIC, json_encode([
            'source' => 'veepoo-node',
            'kind' => 'session',
            'device' => ['mac' => self::BRACELET],
            'payload' => ['connected' => false],
        ], JSON_THROW_ON_ERROR));

        self::assertCount(1, self::eventsOfType($mqtt, 'device.disconnected'));
    }

    /** Uma sessão nova depois de a perder volta a ser um acontecimento. */
    public function testConnectingAgainIsAnnouncedAgain(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = VeepooFixtures::bridge($mqtt);

        foreach ([true, false, true] as $authenticated) {
            $bridge->handleReceivedMessage(self::TOPIC, self::session($authenticated));
        }

        self::assertCount(2, self::eventsOfType($mqtt, 'device.connected'));
    }

    /** O gateway precisa do valor para saber se liga ou desliga, senão o «encontrar dispositivo» nunca pára. */
    public function testTheDesiredValueTravelsWithTheCommand(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = new OneShotDownlinkQueue(
            'config:heart_rate_continuous',
            ['command' => 'config:heart_rate_continuous', 'payload' => ['enabled' => false]],
        );

        VeepooFixtures::bridge($mqtt, $queue)->handleReceivedMessage(self::TOPIC, self::session(true));

        self::assertCount(1, $mqtt->gatewayCommands);
        self::assertSame(
            ['enabled' => false],
            $mqtt->gatewayCommands[0]['payload']['payload'],
        );
    }

    /** Sessão e versão repetem-se a cada trinta segundos e, num histórico de cem entradas, expulsariam tudo o resto numa hora. */
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
        $bridge = VeepooFixtures::bridge($mqtt, null, $store);

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
}
