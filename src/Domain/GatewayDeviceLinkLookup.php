<?php

namespace Hub\Domain;

interface GatewayDeviceLinkLookup
{
    public function isEnabled(string $gatewayDeviceKey, string $linkedDeviceKey): bool;
}
