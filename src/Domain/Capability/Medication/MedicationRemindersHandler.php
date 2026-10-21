<?php

declare(strict_types=1);

namespace Hub\Domain\Capability\Medication;

use Hub\Domain\Capability\CapabilityProtocolHandler;

interface MedicationRemindersHandler extends CapabilityProtocolHandler
{
    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public function responseEntry(string $protocol, string $nativeKey, mixed $value, array $meta): array;
}
