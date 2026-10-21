<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\TimestampFormatter;
use PHPUnit\Framework\TestCase;

final class TimestampFormatterTest extends TestCase
{
    /** Uma coluna a `NULL` continua ausente, e não passa a ser o instante actual. */
    public function testANullTimestampStaysEmpty(): void
    {
        $row = TimestampFormatter::normalizeRow(['created_at' => null, 'updated_at' => '2026-10-21 10:00:00']);

        self::assertSame('', $row['created_at']);
        self::assertSame('2026-10-21T10:00:00Z', $row['updated_at']);
    }
}
