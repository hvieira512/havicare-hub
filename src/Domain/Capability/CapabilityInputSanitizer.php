<?php

namespace Hub\Domain\Capability;

/**
 * Contrato opcional para as capacidades que normalizam a entrada pública da API antes de ela
 * ser convertida, guardada e enviada a um dispositivo.
 */
interface CapabilityInputSanitizer
{
    public function sanitizeInput(string $protocol, mixed $value): mixed;
}
