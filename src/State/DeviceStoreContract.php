<?php

declare(strict_types=1);

namespace Hub\State;

/**
 * Tudo o que o estado de um dispositivo sabe fazer. Só o `DeviceHubServer` e o `DeviceService`
 * precisam dos quatro assuntos; os outros declaram a interface que lhes serve.
 */
interface DeviceStoreContract extends
    DeviceRegistry,
    DeviceReportStore,
    DeviceHistoryStore,
    DeviceCommandLog
{
}
