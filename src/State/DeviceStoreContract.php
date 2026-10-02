<?php

declare(strict_types=1);

namespace Hub\State;

/**
 * Tudo o que o estado de um dispositivo sabe fazer.
 *
 * Quem depende disto depende dos quatro assuntos ao mesmo tempo, e só o `DeviceHubServer` e o
 * `DeviceService` atravessam mesmo tantos. Os outros declaram a interface que lhes serve.
 */
interface DeviceStoreContract extends
    DeviceRegistry,
    DeviceReportStore,
    DeviceHistoryStore,
    DeviceCommandLog
{
}
