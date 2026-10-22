<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Veepoo;

use Hub\Log\Logger;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\VeepooFixtures;

/** O hub recusa o que não está ligado ao gateway, e o aviso leva o travão dos aparelhos não autorizados. */
final class BridgeUnlinkedWarningTest extends TestCase
{
    private const BRACELET = '9f69c4866e6c';
    private const TOPIC = 'havicare-hub/null/0/gw/bef341903987/raw';

    private string $logFile;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'unlinked-log-');
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

    /** Passada a janela, volta a avisar: o par continua a insistir e isso é para se saber. */
    public function testItWarnsAgainOnceTheWindowHasPassed(): void
    {
        $now = 1000;
        $bridge = VeepooFixtures::bridge(linked: false, clock: static function () use (&$now): float {
            return (float)$now;
        });

        $bridge->handleReceivedMessage(self::TOPIC, self::session());
        $now += 30;
        $bridge->handleReceivedMessage(self::TOPIC, self::session());
        self::assertSame(1, $this->warnings(), 'dentro da janela é a mesma queixa');

        $now += 90;
        $bridge->handleReceivedMessage(self::TOPIC, self::session());

        self::assertSame(2, $this->warnings());
    }

    private function warnings(): int
    {
        return substr_count((string)file_get_contents($this->logFile), 'Ignoring unlinked');
    }

    private static function session(): string
    {
        return json_encode([
            'source' => 'veepoo-node',
            'kind' => 'session',
            'device' => ['mac' => self::BRACELET],
            'payload' => ['authenticated' => true],
        ], JSON_THROW_ON_ERROR);
    }
}
