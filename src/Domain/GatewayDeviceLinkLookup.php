<?php

declare(strict_types=1);

namespace Hub\Domain;

interface GatewayDeviceLinkLookup
{
    public function isEnabled(string $gatewayDeviceKey, string $linkedDeviceKey): bool;
}
