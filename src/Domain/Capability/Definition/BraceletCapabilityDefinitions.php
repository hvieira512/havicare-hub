<?php

namespace Hub\Domain\Capability\Definition;

final class BraceletCapabilityDefinitions
{
    /**
     * The button is a single capability rather than one per press mode: the
     * modes are configured on the device, and the press type travels in the
     * event payload. Splitting them here would put three toggles in the
     * capability matrix for what is one physical feature.
     *
     * @return list<array{deviceType: string, section: string, key: string, label: string, sortOrder: int, isTelemetry: bool, isConfigurable: bool, isRequestable: bool, isEvent?: bool}>
     */
    public static function all(): array
    {
        return [
            ['deviceType' => 'bracelet', 'section' => 'telemetry', 'key' => 'battery', 'label' => 'Bateria', 'sortOrder' => 10, 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'bracelet', 'section' => 'alarms', 'key' => 'help_call', 'label' => 'Chamada de ajuda', 'sortOrder' => 30, 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],
        ];
    }
}
