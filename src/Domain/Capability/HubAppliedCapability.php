<?php

declare(strict_types=1);

namespace Hub\Domain\Capability;

/**
 * Contrato opcional para capacidades que o hub aplica sozinho, sem downlink, como a
 * sensibilidade de um medidor de fraldas: o valor guarda-se e dá-se por aplicado.
 */
interface HubAppliedCapability
{
}
