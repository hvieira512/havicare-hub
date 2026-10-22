<?php

declare(strict_types=1);

namespace Tests\Support;

use Hub\Log\Logger;

/** Cada teste escreve o log num ficheiro temporário próprio, que lê em `$this->logFile`. */
trait CapturesLog
{
    private string $logFile;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'test-log-');
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
}
