<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Tcp;

use Hub\Device\DeviceSession;
use Hub\Ingress\Tcp\DeviceEventDecoder;
use Hub\Ingress\Tcp\FourPTouch\FourPTouchAdapter;
use Hub\Ingress\Tcp\FourPTouch\FourPTouchTcpProtocol;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeConnection;

/** O relógio reenvia o alarme até ter resposta, e o reenvio leva a hora em que o alarme disparou. */
final class FourPTouchAlarmOccurredAtTest extends TestCase
{
    public function testAlarmFromThreeHoursAgoKeepsTheWatchTime(): void
    {
        $at = time() - 3 * 3600;

        $telemetry = $this->telemetry(gmdate('dmy', $at), gmdate('His', $at));

        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', $at), $telemetry['help_call']['occurredAt']);
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', $at), $telemetry['location']['occurredAt']);
    }

    public function testAlarmFromTheFutureTakesTheHubClock(): void
    {
        $at = time() + 3600;

        $this->assertHubClock($this->telemetry(gmdate('dmy', $at), gmdate('His', $at)));
    }

    public function testAlarmWithGarbageTimeTakesTheHubClock(): void
    {
        $this->assertHubClock($this->telemetry('xx0916', '99zz53'));
    }

    public function testAlarmFromAWatchWithoutClockTakesTheHubClock(): void
    {
        $this->assertHubClock($this->telemetry('180916', '064153'));
    }

    /** @param array<string, array<string, mixed>> $telemetry */
    private function assertHubClock(array $telemetry): void
    {
        $occurredAt = strtotime((string)$telemetry['help_call']['occurredAt']);
        self::assertEqualsWithDelta(time(), $occurredAt, 5);
    }

    /** @return array<string, array<string, mixed>> */
    private function telemetry(string $date, string $time): array
    {
        $protocol = new FourPTouchTcpProtocol(new FourPTouchAdapter(), new DeviceEventDecoder());
        $session = new DeviceSession(new FakeConnection(), true, '637507597567372', 'four-p-touch');
        $alarm = (new FourPTouchAdapter())->encodeOutgoing([
            'type' => 'AL_LTE',
            'imei' => '7597567372',
            'manufacturer' => '3G',
            'data' => ['fields' => [$date, $time, 'A', '41.15', 'N', '8.61', 'W', '0.0', '0', '0', '5', '55', '44', '0', '0', '00010000']],
        ]);

        $message = $protocol->handleIncoming($session, $alarm);
        self::assertNotNull($message);

        return array_column($message->telemetry, null, 'type');
    }
}
