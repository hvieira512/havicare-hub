<?php

namespace Hub\Domain\Capability;

/**
 * Contrato opcional para capacidades que o hub aplica sozinho.
 *
 * A configuração de um dispositivo viaja: vira um ou mais comandos nativos, sai num
 * downlink, e espera confirmação. Nem toda: a sensibilidade dos alertas de um medidor de
 * fraldas não tem para onde ir -- o sensor é um beacon BLE que só transmite --, e o que
 * muda com ela é a regra com que o hub deriva o estado da fralda da mesma leitura.
 *
 * Não é uma propriedade do tipo de dispositivo. Um relógio pode perfeitamente ter um
 * limiar que o hub decide a partir da telemetria que já recebe, e um medidor de fraldas
 * com downlink teria configurações que viajam. É desta capacidade, com este protocolo, e
 * é por isso que vive aqui e não numa tabela de tipos.
 *
 * Marcar uma capacidade com esta interface muda uma coisa no
 * `DeviceConfigurationUpdateService`: o valor desejado é guardado e dado por aplicado,
 * sem comandos para entregar. O resto do ciclo de vida -- revisão, histórico, o estado
 * que a interface mostra -- é o mesmo das outras.
 */
interface HubAppliedCapability
{
}
