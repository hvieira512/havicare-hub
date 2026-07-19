<?php

namespace Hub\Domain\Capability\Definition;

final class NcsCapabilityDefinitions
{
    /**
     * @return list<array{deviceType: string, section: string, key: string, label: string, sortOrder: int, isTelemetry: bool, isConfigurable: bool, isRequestable: bool, isEvent?: bool}>
     */
    public static function all(): array
    {
        return [
            ['deviceType' => 'ncs', 'section' => 'alarms', 'key' => 'pager_call', 'label' => 'Chamada de enfermagem', 'sortOrder' => 10, 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],
        ];
    }
}
