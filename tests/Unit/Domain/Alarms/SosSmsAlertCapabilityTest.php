<?php

namespace Tests\Unit\Domain\Alarms;

use Hub\Domain\Capability\Alarms\SosSmsAlertCapability;
use PHPUnit\Framework\TestCase;

final class SosSmsAlertCapabilityTest extends TestCase
{
    public function testWonlexUsesSosSwitchButKeepsGenericPublicShape(): void
    {
        $capability = new SosSmsAlertCapability();

        self::assertSame(
            ['wonlexSOSSwitch' => ['switchState' => true]],
            $capability->toNative('wonlex-json', ['enabled' => true])
        );
        self::assertSame(
            ['enabled' => true],
            $capability->fromNative('wonlex-json', 'wonlexSOSSwitch', ['switchState' => 1])
        );
    }

    public function testFourPTouchKeepsItsNativeEnabledField(): void
    {
        $capability = new SosSmsAlertCapability();

        self::assertSame(
            ['sosSmsAlerts' => ['enabled' => false]],
            $capability->toNative('four-p-touch', ['enabled' => false])
        );
        self::assertSame(
            ['enabled' => false],
            $capability->fromNative('four-p-touch', 'sosSmsAlerts', ['enabled' => false])
        );
    }
}
