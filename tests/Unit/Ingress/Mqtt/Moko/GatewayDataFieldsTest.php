<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Moko;

use Hub\Ingress\Mqtt\Moko\GatewayNormalizer;
use PHPUnit\Framework\TestCase;

/** Os nomes do `data` da `connectivity` dos gateways. */
final class GatewayDataFieldsTest extends TestCase
{
    public function testTheCellularRadioIsAnEnum(): void
    {
        self::assertSame(
            ['interface' => 'cellular', 'radioType' => 'lte', 'signalQuality' => 24, 'signalStrengthDbm' => -65],
            $this->connectivity('FDD LTE'),
        );
        self::assertSame('gsm', $this->connectivity('GPRS')['radioType'] ?? null);
        self::assertSame('lte', $this->connectivity('NB-IOT')['radioType'] ?? null);
        self::assertSame('lte', $this->connectivity('CAT.M')['radioType'] ?? null);
    }

    /** O MKGW4 não manda GPS na prática: um `3089` não vira `location`. */
    public function testAGpsFixPublishesNothing(): void
    {
        self::assertSame([], (new GatewayNormalizer())->telemetry(
            ['messageId' => '3089', 'protocol' => 'moko-mkgw4', 'data' => ['fix_result' => 'gps_success', 'latitude' => 41.15, 'longitude' => -8.61]],
            ['imei' => 'e8a1b2c3d4e5', 'supplier' => 'MOKO', 'model' => 'MKGW4'],
        ));
    }

    /** @return array<string, mixed> */
    private function connectivity(string $networkType): array
    {
        $telemetry = (new GatewayNormalizer())->telemetry(
            ['messageId' => '3004', 'protocol' => 'moko-mkgw4', 'data' => ['network_type' => $networkType, 'csq' => 24]],
            ['imei' => 'e8a1b2c3d4e5', 'supplier' => 'MOKO', 'model' => 'MKGW4'],
        );

        return $telemetry[0]['data'];
    }
}
