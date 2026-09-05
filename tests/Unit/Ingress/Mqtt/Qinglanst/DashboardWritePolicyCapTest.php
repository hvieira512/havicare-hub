<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Qinglanst;

use Hub\Ingress\Mqtt\Qinglanst\DashboardWritePolicy;
use PHPUnit\Framework\TestCase;

/**
 * O throttle guarda uma marca por dispositivo; sem teto, cresce com cada aparelho distinto
 * num processo que não reinicia. Ao ultrapassar o teto, a marca mais antiga sai.
 */
final class DashboardWritePolicyCapTest extends TestCase
{
    // Espelha DashboardWritePolicy::MAX_TRACKED.
    private const CAP = 10000;

    public function testDropsTheOldestDeviceOnceTheCapIsReached(): void
    {
        $policy = new DashboardWritePolicy(deviceSeenMinIntervalMs: 5000);
        $now = 1000;

        for ($i = 0; $i < self::CAP; $i++) {
            self::assertTrue($policy->shouldUpdateSeen("d$i", $now));
        }

        // Dentro do intervalo, o mais antigo está estrangulado — prova que está marcado.
        self::assertFalse($policy->shouldUpdateSeen('d0', $now + 1));

        // Um aparelho novo passa o teto e expulsa o mais antigo.
        self::assertTrue($policy->shouldUpdateSeen('overflow', $now + 1));

        // O d0 foi despejado: sem marca, volta a passar mesmo dentro do intervalo.
        self::assertTrue($policy->shouldUpdateSeen('d0', $now + 2));
    }
}
