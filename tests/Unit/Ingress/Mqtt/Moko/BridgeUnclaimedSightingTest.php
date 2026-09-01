<?php

namespace Tests\Unit\Ingress\Mqtt\Moko;

use Hub\Ingress\Mqtt\Moko\ArrayObservationStateStore;
use Hub\Ingress\Mqtt\Moko\Bridge;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/**
 * O sinal de um avistamento que nenhum decoder soube ler. O RSSI é medido pelo gateway e
 * existe quer se saiba interpretar o anúncio, quer não.
 *
 * A W6 é o caso que o expôs: anuncia em seis slots e o decoder lê dois, e por isso rendia
 * um quarto das mensagens de proximidade da W6B a partir de mais avistamentos.
 */
final class BridgeUnclaimedSightingTest extends TestCase
{
    private const GATEWAY = 'd48c49f7909c';
    private const W6 = 'fa05c2c70fc6';
    private const W6B = 'fbd87c59ba8b';
    private const STRANGER = 'aabbccddeeff';

    /** @return array{0: Bridge, 1: RecordingHubMqttBridge} */
    private function bridge(): array
    {
        $mqtt = new RecordingHubMqttBridge();

        $bridge = new Bridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                self::GATEWAY => IngressFixtures::gateway('MKGW3'),
                self::W6 => IngressFixtures::bracelet('W6'),
                self::W6B => IngressFixtures::bracelet('W6B'),
            ]),
            $mqtt,
            IngressFixtures::links(),
            new ArrayObservationStateStore(),
        );

        return [$bridge, $mqtt];
    }

    /**
     * Um slot que nenhum decoder reclama. O TLM da W6 é exactamente isto: sempre ligado,
     * sempre ignorado.
     *
     * @param array<string, mixed> $overrides
     */
    private function unclaimedPayload(array $overrides = []): string
    {
        return json_encode([
            'msg_id' => 3070,
            'device_info' => ['mac' => self::GATEWAY],
            'data' => [$overrides + [
                'type_code' => 3,
                'type' => 'eddystone-tlm',
                'rssi' => -61,
                'connectable' => 0,
                'mac' => self::W6,
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    private function deliver(Bridge $bridge, string $payload): void
    {
        $bridge->handleReceivedMessage(
            'havicare-hub/null/0/gw/' . self::GATEWAY . '/raw',
            $payload,
        );
    }

    /** @param list<array<string, mixed>> $published @return list<array<string, mixed>> */
    private function proximity(array $published): array
    {
        return array_values(array_filter(
            $published,
            static fn(array $e): bool => ($e['payload']['type'] ?? '') === 'proximity',
        ));
    }

    public function testAFrameNoDecoderClaimsStillReportsTheSignal(): void
    {
        [$bridge, $mqtt] = $this->bridge();

        $this->deliver($bridge, $this->unclaimedPayload());

        $proximity = $this->proximity($mqtt->telemetry);
        self::assertCount(1, $proximity);
        self::assertSame(-61, $proximity[0]['payload']['data']['rssiDbm']);
        self::assertSame(self::W6, $proximity[0]['payload']['device']['id']);
    }

    /**
     * O tipo sozinho não distingue as pulseiras, e uma W6 reportada como `moko-w6b` mandava o
     * cliente ler o protocolo errado.
     */
    public function testTheModelDecidesTheProtocolReportedForABracelet(): void
    {
        [$bridge, $mqtt] = $this->bridge();

        $this->deliver($bridge, $this->unclaimedPayload());

        $proximity = $this->proximity($mqtt->telemetry);
        self::assertSame('moko-w6', $proximity[0]['payload']['source']['protocol']);
    }

    /** Um beacon qualquer que passe continua a não ser assunto nosso. */
    public function testAnUnknownDeviceIsStillIgnored(): void
    {
        [$bridge, $mqtt] = $this->bridge();

        $this->deliver($bridge, $this->unclaimedPayload(['mac' => self::STRANGER]));

        self::assertSame([], $this->proximity($mqtt->telemetry));
    }

    /** Sem RSSI não há nada a reportar: a medição é que era o conteúdo. */
    public function testASightingWithoutSignalReportsNothing(): void
    {
        [$bridge, $mqtt] = $this->bridge();

        $this->deliver($bridge, $this->unclaimedPayload(['rssi' => null]));

        self::assertSame([], $this->proximity($mqtt->telemetry));
    }

    /**
     * Uma frame reclamada continua a dar uma mensagem e não duas: o caminho novo é a saída
     * de quem não foi reclamado, não um segundo relato por cima do primeiro.
     */
    public function testAClaimedFrameIsNotReportedTwice(): void
    {
        [$bridge, $mqtt] = $this->bridge();

        $this->deliver($bridge, json_encode([
            'msg_id' => 3070,
            'device_info' => ['mac' => self::GATEWAY],
            'data' => [[
                'type_code' => 7, 'type' => 'bxp-button', 'rssi' => -70, 'connectable' => 1,
                'mac' => self::W6B, 'frame_type' => 0, 'passwd_verification' => 1,
                'alarm_status' => 1, 'trigger_count' => 69, 'device_id' => '000001',
                'adv_name' => 'MK Button', 'batt_vol' => 98,
                'x_axis_data' => -4, 'y_axis_data' => -20, 'z_axis_data' => 1052,
            ]],
        ], JSON_THROW_ON_ERROR));

        self::assertCount(1, $this->proximity($mqtt->telemetry));
    }
}
