<?php

namespace Hub\Domain\Capability;

/**
 * Optional contract for capabilities that normalize accepted public API input
 * before it is converted, persisted, and sent to a device.
 */
interface CapabilityInputSanitizer
{
    public function sanitizeInput(string $protocol, mixed $value): mixed;
}
