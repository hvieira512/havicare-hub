<?php

namespace Hub\Domain\Capability;

/**
 * Contrato opcional para capacidades que o hub aplica sozinho, sem downlink -- a
 * sensibilidade de um medidor de fraldas não tem para onde ir, e o que muda com ela é a
 * regra com que o hub interpreta a leitura.
 *
 * É da capacidade e do protocolo, e não do tipo de dispositivo: um medidor com downlink
 * teria configurações que viajam.
 *
 * Marcá-la muda uma coisa no `DeviceConfigurationUpdateService`: o valor é guardado e dado
 * por aplicado, sem comandos. O resto do ciclo de vida é o das outras.
 */
interface HubAppliedCapability
{
}
