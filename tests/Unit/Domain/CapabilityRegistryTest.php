<?php

namespace Tests\Unit\Domain;

use Hub\Domain\Capability\CapabilityRegistry;
use PHPUnit\Framework\TestCase;

final class CapabilityRegistryTest extends TestCase
{
    public function testVivistarAutoVitalsIntervalMapsToAutoHealthMeasurement(): void
    {
        $registry = new CapabilityRegistry();

        self::assertSame(
            ['autoHealthMeasurement' => ['enabled' => false, 'intervalMinutes' => 0]],
            $registry->toNative('vivistar-iw', 'auto_vitals_interval', [
                'enabled' => false,
                'intervalMinutes' => 0,
            ])
        );
    }
}
