<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Hub\Runtime\CrashWatch;
use PHPUnit\Framework\TestCase;

final class CrashWatchTest extends TestCase
{
    private string $directory;
    private string $marker;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/hub-crashwatch-' . bin2hex(random_bytes(6));
        $this->marker = $this->directory . '/hub-boot.marker';
    }

    protected function tearDown(): void
    {
        if (is_file($this->marker)) {
            unlink($this->marker);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    /** Um primeiro arranque não tem anterior nenhum, e isso não é uma queda. */
    public function testTheFirstBootIsNotReportedAsACrash(): void
    {
        self::assertNull((new CrashWatch($this->marker))->claimBoot());
        self::assertFileExists($this->marker, 'o arranque tem de deixar o seu marcador');
    }

    public function testABootAfterACleanShutdownIsNotReportedAsACrash(): void
    {
        $first = new CrashWatch($this->marker);
        $first->claimBoot();
        $first->markCleanShutdown();

        self::assertFileDoesNotExist($this->marker);
        self::assertNull((new CrashWatch($this->marker))->claimBoot());
    }

    /** A queda sai como dados, e o texto que a conta é da dashboard. */
    public function testABootOverASurvivingMarkerReportsThePreviousRun(): void
    {
        (new CrashWatch($this->marker))->claimBoot();

        $reported = (new CrashWatch($this->marker))->claimBoot();

        self::assertSame(getmypid(), $reported['pid'] ?? null);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string)($reported['startedAt'] ?? ''));
    }

    /** Duas quedas seguidas continuam a ser quedas: o marcador novo substitui o antigo. */
    public function testConsecutiveCrashesAreEachReported(): void
    {
        (new CrashWatch($this->marker))->claimBoot();

        self::assertIsArray((new CrashWatch($this->marker))->claimBoot());
        self::assertIsArray((new CrashWatch($this->marker))->claimBoot());
    }

    public function testAMarkerWithoutTheExpectedShapeStillReportsACrash(): void
    {
        mkdir($this->directory, 0755, true);
        file_put_contents($this->marker, 'lixo');

        self::assertSame(['pid' => null, 'startedAt' => null], (new CrashWatch($this->marker))->claimBoot());
    }
}
