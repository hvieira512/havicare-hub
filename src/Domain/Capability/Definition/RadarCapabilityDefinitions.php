<?php

namespace Hub\Domain\Capability\Definition;

final class RadarCapabilityDefinitions
{
    /**
     * @return list<array{deviceType: string, section: string, key: string, label: string, sortOrder: int, isTelemetry: bool, isConfigurable: bool, isRequestable: bool, isEvent?: bool}>
     */
    public static function all(): array
    {
        return [
            ['deviceType' => 'radar', 'section' => 'telemetry', 'key' => 'positions', 'label' => 'Positions', 'sortOrder' => 10, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'radar', 'section' => 'telemetry', 'key' => 'vitals', 'label' => 'Vitals', 'sortOrder' => 20, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'radar', 'section' => 'telemetry', 'key' => 'position_minute_stats', 'label' => 'Position minute stats', 'sortOrder' => 30, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'radar', 'section' => 'telemetry', 'key' => 'vitals_minute_stats', 'label' => 'Vitals minute stats', 'sortOrder' => 40, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
        ];
    }
}
