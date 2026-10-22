<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Veepoo;

use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\VeepooFixtures;
use Tests\Support\CapturesLog;

/** O hub recusa o que não está ligado ao gateway, e o aviso leva o travão dos aparelhos não autorizados. */
final class BridgeUnlinkedWarningTest extends TestCase
{
    use CapturesLog;

    private const TOPIC = 'havicare-hub/null/0/gw/bef341903987/raw';

    /** Passada a janela, volta a avisar: o par continua a insistir e isso é para se saber. */
    public function testItWarnsAgainOnceTheWindowHasPassed(): void
    {
        $now = 1000;
        $bridge = VeepooFixtures::bridge(linked: false, clock: static function () use (&$now): float {
            return (float)$now;
        });

        IngressFixtures::relay($bridge, self::TOPIC, self::session());
        $now += 30;
        IngressFixtures::relay($bridge, self::TOPIC, self::session());
        self::assertSame(1, $this->warnings(), 'dentro da janela é a mesma queixa');

        $now += 90;
        IngressFixtures::relay($bridge, self::TOPIC, self::session());

        self::assertSame(2, $this->warnings());
    }

    private function warnings(): int
    {
        return substr_count((string)file_get_contents($this->logFile), 'Ignoring unlinked');
    }

    private static function session(): string
    {
        return VeepooFixtures::message('session', ['authenticated' => true]);
    }
}
