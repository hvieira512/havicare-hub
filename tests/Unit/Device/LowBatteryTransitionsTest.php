<?php

declare(strict_types=1);

namespace Tests\Unit\Device;

use Hub\Device\LowBatteryTransitions;
use PHPUnit\Framework\TestCase;

/**
 * Os aparelhos que repetem a bandeira de bateria fraca em cada leitura dão um `low_battery` só
 * quando ela acende, e não um por leitura.
 */
final class LowBatteryTransitionsTest extends TestCase
{
    public function testTheFlagRaisesTheEventWhenItTurnsOn(): void
    {
        $transitions = new LowBatteryTransitions();

        $event = $transitions->observe('a', $this->battery(['percent' => 14, 'lowBattery' => true]));

        self::assertSame('low_battery', $event['type'] ?? null);
        self::assertSame(['percent' => 14], $event['data']);
        self::assertSame(['protocol' => 'zayata-m228', 'nativeType' => 'heartbeat'], $event['source']);
        self::assertSame('2026-10-08T10:00:00Z', $event['occurredAt']);
    }

    public function testTheFlagStillOnRaisesNothing(): void
    {
        $transitions = new LowBatteryTransitions();
        $transitions->observe('a', $this->battery(['lowBattery' => true]));

        self::assertNull($transitions->observe('a', $this->battery(['lowBattery' => true])));
    }

    public function testChargingAndRunningLowAgainRaisesItAgain(): void
    {
        $transitions = new LowBatteryTransitions();
        $transitions->observe('a', $this->battery(['lowBattery' => true]));
        $transitions->observe('a', $this->battery(['lowBattery' => false]));

        self::assertNotNull($transitions->observe('a', $this->battery(['lowBattery' => true])));
    }

    /** Uma leitura que não diz nada sobre a bandeira não a apaga. */
    public function testAReadingWithoutTheFlagChangesNothing(): void
    {
        $transitions = new LowBatteryTransitions();
        $transitions->observe('a', $this->battery(['lowBattery' => true]));
        $transitions->observe('a', $this->battery(['percent' => 12]));

        self::assertNull($transitions->observe('a', $this->battery(['lowBattery' => true])));
    }

    /** O alarme que o próprio aparelho dispara já é o evento: a bandeira a seguir não o repete. */
    public function testTheDevicesOwnAlarmCountsAsTheFlagTurningOn(): void
    {
        $transitions = new LowBatteryTransitions();

        self::assertNull($transitions->observe('a', ['type' => 'low_battery', 'data' => []]));
        self::assertNull($transitions->observe('a', $this->battery(['lowBattery' => true])));
    }

    public function testEachDeviceHasItsOwnFlag(): void
    {
        $transitions = new LowBatteryTransitions();
        $transitions->observe('a', $this->battery(['lowBattery' => true]));

        self::assertNotNull($transitions->observe('b', $this->battery(['lowBattery' => true])));
    }

    public function testTheGatewayVoltageTravelsWithTheEvent(): void
    {
        $event = (new LowBatteryTransitions())->observe('a', $this->battery(['voltageMv' => 3380, 'lowBattery' => true]));

        self::assertSame(['voltageMv' => 3380], $event['data'] ?? null);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function battery(array $data): array
    {
        return [
            'type' => 'battery',
            'occurredAt' => '2026-10-08T10:00:00Z',
            'device' => ['id' => 'a'],
            'source' => ['protocol' => 'zayata-m228', 'nativeType' => 'heartbeat'],
            'data' => $data,
        ];
    }
}
