<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Qinglanst;

use Hub\Ingress\Mqtt\Qinglanst\DashboardWritePolicy;
use PHPUnit\Framework\TestCase;

/** O que o radar escreve no histórico da dashboard: a telemetria toda, e o raw por amostra. */
final class DashboardWritePolicyTest extends TestCase
{
    /** O raw é amostrado: não alimenta o stream, e um radar publica muitas vezes por segundo. */
    public function testRawIsSampledForTheHistory(): void
    {
        $policy = new DashboardWritePolicy(rawHistorySampleMs: 30000);

        self::assertTrue($policy->shouldStoreRaw('radar-1', 0), 'a primeira vai para o histórico');
        self::assertFalse($policy->shouldStoreRaw('radar-1', 5000), 'dentro da janela, não');
        self::assertFalse($policy->shouldStoreRaw('radar-1', 29999), 'ainda dentro da janela');
        self::assertTrue($policy->shouldStoreRaw('radar-1', 30000), 'passada a janela, vai');
    }

    /** A janela do raw é por dispositivo, e desliga-se com o intervalo a zero. */
    public function testRawSamplingIsPerDeviceAndCanBeDisabled(): void
    {
        $policy = new DashboardWritePolicy(rawHistorySampleMs: 30000);
        self::assertTrue($policy->shouldStoreRaw('radar-1', 0));
        self::assertTrue($policy->shouldStoreRaw('radar-2', 0), 'outro radar não é calado pelo primeiro');

        $off = new DashboardWritePolicy(rawHistorySampleMs: 0);
        self::assertTrue($off->shouldStoreRaw('radar-1', 0));
        self::assertTrue($off->shouldStoreRaw('radar-1', 1), 'com a amostragem a zero, tudo vai');
    }

    /** O «visto há» continua travado: é escrita idempotente e não chega ao stream. */
    public function testTheSeenWriteIsStillThrottled(): void
    {
        $policy = new DashboardWritePolicy(deviceSeenMinIntervalMs: 5000);

        self::assertTrue($policy->shouldUpdateSeen('radar-1', 0));
        self::assertFalse($policy->shouldUpdateSeen('radar-1', 4999));
        self::assertTrue($policy->shouldUpdateSeen('radar-1', 5000));
    }
}
