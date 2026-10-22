<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Moko;

use Hub\Ingress\Mqtt\Gateway\GatewayRelay;
use Hub\Ingress\Mqtt\JoyTech\JoyTechBpBridge;
use Hub\Ingress\Mqtt\JoyTech\JoyTechOximeterBridge;
use Hub\Ingress\Mqtt\JoyTech\JoyTechThermometerBridge;
use Hub\Ingress\Mqtt\Lefu\LefuTorreBridge;
use Hub\Ingress\Mqtt\Moko\MokoBridge;
use Hub\Ingress\Mqtt\Veepoo\VeepooBridge;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\ArrayObservationStateStore;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/** O subscritor único do `…/gw/+/raw` descodifica uma vez e entrega cada mensagem à ponte dela. */
final class GatewayRoutingTest extends TestCase
{
    private const TOPIC = 'havicare-hub/null/0/gw/d48c49f7909c/raw';

    private const RELAYS = [
        VeepooBridge::class,
        LefuTorreBridge::class,
        JoyTechBpBridge::class,
        JoyTechOximeterBridge::class,
        JoyTechThermometerBridge::class,
    ];

    /** @return iterable<string, array{string, string, class-string}> */
    public static function relayedMessages(): iterable
    {
        yield 'veepoo bracelet' => ['veepoo-node', 'VPW1', VeepooBridge::class];
        yield 'lefu scale' => ['lefu-torre-node', 'HC-CF632BLE', LefuTorreBridge::class];
        yield 'joytech blood pressure monitor' => ['joytech-node', 'DBP-6292B', JoyTechBpBridge::class];
        yield 'joytech oximeter' => ['joytech-node', 'XM-801', JoyTechOximeterBridge::class];
        yield 'joytech thermometer' => ['joytech-node', 'DET-306', JoyTechThermometerBridge::class];
    }

    /** @param class-string<GatewayRelay> $bridge */
    #[DataProvider('relayedMessages')]
    public function testEachSourceIsClaimedByItsBridgeAlone(string $source, string $model, string $bridge): void
    {
        $message = ['source' => $source, 'device' => ['model' => $model]];

        $claimants = array_filter(self::RELAYS, static fn (string $relay): bool => $relay::claims($message));

        self::assertSame([$bridge], array_values($claimants));
    }

    public function testAMessageFromAnUnknownSourceIsClaimedByNoBridge(): void
    {
        $message = ['source' => 'other-node', 'device' => ['model' => 'DBP-6292B']];

        self::assertSame([], array_filter(self::RELAYS, static fn (string $relay): bool => $relay::claims($message)));
    }

    public function testTheGatewayHandsARelayedMessageToTheBridgeThatClaimsIt(): void
    {
        $joyTech = new JoyTechRelay();
        $veepoo = new VeepooRelay();
        $message = ['source' => 'joytech-node', 'kind' => 'session', 'device' => ['mac' => '748b34838278']];

        $this->bridge(new RecordingHubMqttBridge(), [$veepoo, $joyTech])
            ->handleReceivedMessage(self::TOPIC, (string)json_encode($message));

        self::assertSame([[self::TOPIC, $message]], $joyTech->received);
        self::assertSame([], $veepoo->received);
    }

    public function testAMokoScanReachesNoRelay(): void
    {
        $relay = new JoyTechRelay();
        $mqtt = new RecordingHubMqttBridge();

        $this->bridge($mqtt, [$relay])->handleReceivedMessage(
            self::TOPIC,
            IngressFixtures::monitScan('d48c49f7909c', 'eec5000202f9', 'clean'),
        );

        self::assertSame([], $relay->received);
        self::assertNotSame([], $mqtt->telemetry);
    }

    /** @param list<GatewayRelay> $relays */
    private function bridge(RecordingHubMqttBridge $mqtt, array $relays): MokoBridge
    {
        return new MokoBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                'd48c49f7909c' => IngressFixtures::gateway('MKGW3'),
                'eec5000202f9' => IngressFixtures::diaperSensor(),
            ]),
            $mqtt,
            IngressFixtures::links(),
            new ArrayObservationStateStore(),
            relays: $relays,
        );
    }
}

/** Uma ponte que só guarda o que lhe chegou; reclama as mensagens da sua fonte. */
abstract class RecordingRelay implements GatewayRelay
{
    protected const SOURCE = '';

    /** @var list<array{string, array<string, mixed>}> */
    public array $received = [];

    public static function claims(array $message): bool
    {
        return ($message['source'] ?? null) === static::SOURCE;
    }

    public function handle(string $topic, array $message): void
    {
        $this->received[] = [$topic, $message];
    }
}

final class JoyTechRelay extends RecordingRelay
{
    protected const SOURCE = 'joytech-node';
}

final class VeepooRelay extends RecordingRelay
{
    protected const SOURCE = 'veepoo-node';
}
