<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Hub\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigFlagTest extends TestCase
{
    private const NAME = 'HUB_TEST_FLAG';

    protected function tearDown(): void
    {
        putenv(self::NAME);
    }

    /** @return iterable<string, array{string|null, bool, bool}> */
    public static function values(): iterable
    {
        yield 'unset keeps the default true' => [null, true, true];
        yield 'unset keeps the default false' => [null, false, false];
        yield 'blank keeps the default' => ['  ', true, true];
        yield 'true' => ['true', false, true];
        yield 'one' => ['1', false, true];
        yield 'yes in capitals' => ['YES', false, true];
        yield 'on with spaces' => [' on ', false, true];
        yield 'false' => ['false', true, false];
        yield 'zero' => ['0', true, false];
        yield 'no' => ['no', true, false];
        yield 'off' => ['Off', true, false];
        yield 'anything else keeps the default' => ['maybe', true, true];
    }

    #[DataProvider('values')]
    public function testFlagReadsTheUsualSpellings(?string $value, bool $default, bool $expected): void
    {
        putenv($value === null ? self::NAME : self::NAME . '=' . $value);

        self::assertSame($expected, Config::flag(self::NAME, $default));
    }
}
