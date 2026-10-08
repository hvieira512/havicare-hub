<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Device\DeviceEventDecoder;
use Hub\Device\DeviceSession;
use Hub\Ingress\Tcp\Supplier\Zayata\PillDispenserTcpProtocol;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

/**
 * O heartbeat do M228 repete as TAGs de estado a cada minuto: uma avaria ou uma chamada que dura
 * dá um evento quando começa, e não um por heartbeat.
 */
final class PillDispenserRepeatedConditionsTest extends TestCase
{
    private const FAULT_TRAY = 0x8122;
    private const FAULT_PUSHER = 0x8123;
    private const EMERGENCY = 0x8112;
    private const ENVIRONMENT = 0x8111;

    public function testAFaultThatLastsRaisesOneEvent(): void
    {
        $protocol = $this->protocol();

        $types = [
            ...$this->conditions($protocol, [self::FAULT_TRAY => 1]),
            ...$this->conditions($protocol, [self::FAULT_TRAY => 1]),
            ...$this->conditions($protocol, [self::FAULT_TRAY => 1]),
        ];

        self::assertSame(['device_fault:tray_reset'], $types);
    }

    /** Limpa no aparelho e volta: é uma avaria nova. */
    public function testAFaultThatClearsAndReturnsRaisesItAgain(): void
    {
        $protocol = $this->protocol();

        $types = [
            ...$this->conditions($protocol, [self::FAULT_TRAY => 1]),
            ...$this->conditions($protocol, [self::FAULT_TRAY => 0]),
            ...$this->conditions($protocol, [self::FAULT_TRAY => 1]),
        ];

        self::assertSame(['device_fault:tray_reset', 'device_fault:tray_reset'], $types);
    }

    /** Um pacote que não traz a TAG não diz que a avaria passou. */
    public function testAPacketWithoutTheTagDoesNotClearIt(): void
    {
        $protocol = $this->protocol();

        $types = [
            ...$this->conditions($protocol, [self::FAULT_TRAY => 1]),
            ...$this->conditions($protocol, [self::FAULT_PUSHER => 0]),
            ...$this->conditions($protocol, [self::FAULT_TRAY => 1]),
        ];

        self::assertSame(['device_fault:tray_reset'], $types);
    }

    public function testEachConditionHasItsOwnState(): void
    {
        $protocol = $this->protocol();

        $types = [
            ...$this->conditions($protocol, [self::FAULT_TRAY => 1, self::EMERGENCY => 1]),
            ...$this->conditions($protocol, [self::FAULT_TRAY => 1, self::EMERGENCY => 1, self::FAULT_PUSHER => 1, self::ENVIRONMENT => 1]),
        ];

        self::assertSame(
            ['help_call:', 'device_fault:tray_reset', 'storage_environment:', 'device_fault:pusher'],
            $types,
        );
    }

    /**
     * @param array<int, int> $tags
     * @return list<string>
     */
    private function conditions(PillDispenserTcpProtocol $protocol, array $tags): array
    {
        $adapter = new PillDispenserAdapter();
        $tlv = [];
        foreach ($tags as $tag => $value) {
            $tlv[$tag] = ['value' => chr($value)];
        }
        $message = $protocol->handleIncoming($this->session(), $adapter->encodeOutgoing([
            'packetType' => 0x02,
            'mac' => 'AABBCCDDEEFF',
            'tlv' => $tlv,
        ]));
        self::assertNotNull($message);

        $types = [];
        foreach ($message->telemetry as $event) {
            if (in_array($event['type'], ['device_fault', 'help_call', 'storage_environment'], true)) {
                $types[] = $event['type'] . ':' . ($event['data']['fault'] ?? '');
            }
        }

        return $types;
    }

    private function protocol(): PillDispenserTcpProtocol
    {
        return new PillDispenserTcpProtocol(new PillDispenserAdapter(), new DeviceEventDecoder());
    }

    private function session(): DeviceSession
    {
        return new DeviceSession(
            new PillFakeConnection(),
            'tcp',
            true,
            'AABBCCDDEEFF',
            'zayata-m228',
            'Zayata',
            'M228',
            'Zayata M228',
            'pill_dispenser',
        );
    }
}
