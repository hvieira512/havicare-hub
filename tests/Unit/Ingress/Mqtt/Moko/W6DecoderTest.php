<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Moko;

use Hub\Ingress\Mqtt\Moko\W6Decoder;
use PHPUnit\Framework\TestCase;

/**
 * A leitura de uma MOKO W6 a partir do que um gateway retransmite. As observações são as que
 * um MKGW3 publica: as tramas MOKO e Eddystone chegam já lidas, sem os bytes do anúncio.
 */
final class W6DecoderTest extends TestCase
{
    private const MAC = 'fa05c2c70fc6';

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function uid(array $overrides = []): array
    {
        return $overrides + [
            'type' => 'eddystone-uid',
            'rssi' => -44,
            'mac' => self::MAC,
            'namespace' => '00000000fa05c2c70fc6',
            'instance' => '000000000011',
        ];
    }

    public function testEachPressInstanceDecodesToItsMode(): void
    {
        $decoder = new W6Decoder();
        $modes = [
            '000000000011' => 'single',
            '000000000012' => 'double',
            '000000000013' => 'triple',
        ];

        foreach ($modes as $instance => $expected) {
            $decoded = $decoder->decode($this->uid(['instance' => $instance]));
            self::assertSame($expected, $decoded['alarm']['pressMode'] ?? null, "instance {$instance}");
            self::assertSame(self::MAC, $decoded['mac']);
            self::assertSame(-44, $decoded['rssiDbm']);
        }
    }

    /**
     * O slot de identidade anuncia-se sempre: é um avistamento e não um toque, e dá-lo como
     * alarme levantava um pedido de ajuda a cada varrimento do gateway.
     */
    public function testTheIdentitySlotIsASightingWithoutAnAlarm(): void
    {
        $decoded = (new W6Decoder())->decode($this->uid(['instance' => '000000000001']));

        self::assertSame(self::MAC, $decoded['mac']);
        self::assertArrayNotHasKey('alarm', $decoded);
    }

    /**
     * Other Eddystone beacons are in range with low instance ids of their own, so the
     * namespace -- which our configuration writes as the bracelet's own MAC -- is what keeps
     * them from being read as W6 presses.
     */
    public function testAForeignNamespaceIsNotClaimed(): void
    {
        $decoded = (new W6Decoder())->decode($this->uid([
            'mac' => 'e406bfa7221a',
            'namespace' => '00000000000000000001',
            'instance' => '000000000011',
        ]));

        self::assertNull($decoded);
    }

    public function testTheAccelerometerFrameCarriesBatteryAndMotion(): void
    {
        $decoded = (new W6Decoder())->decode([
            'type' => 'bxp-acc',
            'rssi' => -33,
            'mac' => self::MAC,
            'x_axis_data' => -956,
            'y_axis_data' => 272,
            'z_axis_data' => 140,
            'batt_vol' => 2808,
        ]);

        self::assertSame(2808, $decoded['info']['batteryVoltageMv'] ?? null);
        self::assertSame(['x' => -956, 'y' => 272, 'z' => 140], $decoded['info']['accelerationMg'] ?? null);
        self::assertArrayNotHasKey('alarm', $decoded);
    }

    public function testAnUnrelatedFrameTypeIsIgnored(): void
    {
        self::assertNull((new W6Decoder())->decode([
            'type' => 'ibeacon',
            'mac' => self::MAC,
            'rssi' => -50,
        ]));
    }
}
