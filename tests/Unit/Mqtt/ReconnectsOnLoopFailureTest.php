<?php

declare(strict_types=1);

namespace Tests\Unit\Mqtt;

use Hub\Mqtt\ReconnectsOnLoopFailure;
use PHPUnit\Framework\TestCase;
use Tests\Support\CapturesLog;

/** Uma ligação que cai diz quando volta, para se distinguir de uma que nunca mais voltou. */
final class ReconnectsOnLoopFailureTest extends TestCase
{
    use CapturesLog;

    public function testARecoveredConnectionSaysSo(): void
    {
        $subject = new ReconnectingDouble();
        $subject->fail(new \RuntimeException('No ping response received in time'));

        $log = (string)file_get_contents($this->logFile);

        self::assertStringContainsString('connection lost', $log);
        self::assertStringContainsString('reconnected', $log);
    }

    /** E uma que não volta continua a dizer só que falhou. */
    public function testAFailedReconnectDoesNotClaimToHaveRecovered(): void
    {
        $subject = new ReconnectingDouble();
        $subject->fail(new \RuntimeException('No ping response received in time'), broken: true);

        $log = (string)file_get_contents($this->logFile);

        self::assertStringContainsString('reconnect failed', $log);
        self::assertStringNotContainsString('reconnected', $log);
    }
}

/** Um ingress de mentira, só para o traço poder ser exercitado sem broker nenhum. */
final class ReconnectingDouble
{
    use ReconnectsOnLoopFailure;

    public function fail(\Throwable $failure, bool $broken = false): void
    {
        $this->reconnectAfterLoopFailure(
            $failure,
            'test ingress',
            static function () use ($broken) {
                if ($broken) {
                    throw new \RuntimeException('broker unreachable');
                }

                return null;
            },
            static fn(): null => null,
        );
    }
}
