<?php

namespace Hub\Domain\Capability;

/**
 * Contrato opcional para capacidades que o hub aplica sozinho, sem downlink -- a
 * sensibilidade de um medidor de fraldas não tem para onde ir, e o que muda com ela é a
 * regra com que o hub interpreta a leitura.
 *
 * É da capacidade e do protocolo, e não do tipo de dispositivo. Marcá-la muda uma coisa no
 * `DeviceConfigurationUpdateService`: o valor é guardado e dado por aplicado, sem comandos.
 */
interface HubAppliedCapability
{
}
