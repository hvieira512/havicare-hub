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

    public function testFourPTouchUsesRejectUnknownCallsAsNativeKey(): void
    {
        $capability = new WhitelistEnabledCapability();

        self::assertSame(
            ['rejectUnknownCalls' => ['enabled' => true]],
            $capability->toNative('four-p-touch', ['enabled' => true])
        );
    }

    public function testWonlexUsesCallInLimitSwitchInsideDeviceConfig(): void
    {
        $capability = new WhitelistEnabledCapability();

        self::assertSame(
            ['wonlexCallInLimitSwitch' => ['switchState' => true]],
            $capability->toNative('wonlex-json', ['enabled' => true])
        );
        self::assertSame(
            ['enabled' => true],
            $capability->fromNative('wonlexCallInLimitSwitch', ['switchState' => 1])
        );
        self::assertSame(
            ['phonebook', 'sos_contacts'],
            $capability->meta('wonlex-json')['allowedContactSources'] ?? null
        );
    }
}
