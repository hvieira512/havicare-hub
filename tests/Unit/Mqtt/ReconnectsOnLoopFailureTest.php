<?php

declare(strict_types=1);

namespace Tests\Unit\Mqtt;

use Hub\Log\Logger;
use Hub\Mqtt\ReconnectsOnLoopFailure;
use PHPUnit\Framework\TestCase;

/**
 * Uma ligação que cai tem de dizer também quando voltou.
 *
 * O registo dizia «connection lost ...; reconnecting» e mais nada: se a reconexão corria bem,
 * ficava calado. Num dia com vinte quedas por hora -- que é o que o diário deste hub mostrou
 * durante vinte horas seguidas -- quem o lê não consegue distinguir uma ligação a oscilar e a
 * recuperar sempre de uma que caiu de madrugada e nunca mais voltou. As duas dão a mesma
 * parede de avisos.
 */
final class ReconnectsOnLoopFailureTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'reconnect-log-');
        if ($path === false) {
            throw new \RuntimeException('could not create the temporary log file');
        }
        $this->logFile = $path;
        putenv('LOG_FILE=' . $path);
        Logger::reset();
    }

    protected function tearDown(): void
    {
        putenv('LOG_FILE');
        Logger::reset();
        @unlink($this->logFile);
    }

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
