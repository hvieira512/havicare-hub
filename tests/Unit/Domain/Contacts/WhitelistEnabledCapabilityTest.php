<?php

namespace Tests\Unit\Domain\Contacts;

use Hub\Domain\Capability\Contacts\WhitelistEnabledCapability;
use PHPUnit\Framework\TestCase;

final class WhitelistEnabledCapabilityTest extends TestCase
{
    public function testVivistarUsesCatalogKeyForWritesAndBp84ForReads(): void
    {
        $capability = new WhitelistEnabledCapability();

        self::assertSame(
            ['whitelist_enabled' => ['enabled' => false]],
            $capability->toNative('vivistar-iw', ['enabled' => false])
        );
    }

    public function testFourPTouchKeepsWhitelistSwitchAsNativeKey(): void
    {
        $capability = new WhitelistEnabledCapability();

        self::assertSame(
            ['whitelistSwitch' => ['enabled' => true]],
            $capability->toNative('four-p-touch', ['enabled' => true])
        );
    }
}
