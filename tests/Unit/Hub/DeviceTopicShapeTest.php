<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\HubMqttBridge;
use PHPUnit\Framework\TestCase;

/**
 * Published topics are an external contract -- consumers subscribe to these
 * strings. licenseId is an int in the domain and only becomes text here, so
 * this pins the rendering across that boundary.
 */
final class DeviceTopicShapeTest extends TestCase
{
    private function bridge(): HubMqttBridge
    {
        return (new \ReflectionClass(HubMqttBridge::class))->newInstanceWithoutConstructor();
    }

    public function testAnAssignedDeviceRendersCompanyAndLicense(): void
    {
        self::assertSame(
            'havicare/1/watch/861265061009822/telemetry',
            $this->bridge()->deviceTopic('havicare', 1, 'watch', '861265061009822', 'telemetry')
        );
    }

    public function testAnUnassignedDeviceRendersNullAndZero(): void
    {
        self::assertSame(
            'null/0/gw/d48c49f7909c/raw',
            $this->bridge()->deviceTopic('null', 0, 'gw', 'd48c49f7909c', 'raw')
        );
    }

    public function testLargerLicenseIdsAreNotReformatted(): void
    {
        self::assertSame(
            'hitcare/1001/watch/861265061009822/events',
            $this->bridge()->deviceTopic('hitcare', 1001, 'watch', '861265061009822', 'events')
        );
    }

    public function testSlashesInTheCompanyCannotSplitTheTopic(): void
    {
        self::assertSame(
            'havicare/1/watch/861265061009822/status',
            $this->bridge()->deviceTopic('/havicare/', 1, 'watch', '861265061009822', 'status')
        );
    }
}
