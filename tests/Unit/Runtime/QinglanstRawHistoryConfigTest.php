<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Hub\Config;
use PHPUnit\Framework\TestCase;

final class QinglanstRawHistoryConfigTest extends TestCase
{
    private const NAME = 'QINGLANST_RAW_HISTORY_SAMPLE_MS';

    protected function tearDown(): void
    {
        putenv(self::NAME);
    }

    /** O `.env.example` promete que o zero desliga a amostragem. */
    public function testZeroTurnsTheSamplingOff(): void
    {
        putenv(self::NAME . '=0');

        self::assertSame(0, Config::load()->all()['qinglanst']['raw_history_sample_ms']);
    }

    public function testUnsetKeepsTheDefault(): void
    {
        putenv(self::NAME);

        self::assertSame(30000, Config::load()->all()['qinglanst']['raw_history_sample_ms']);
    }
}
