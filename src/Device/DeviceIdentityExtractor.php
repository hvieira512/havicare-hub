<?php

namespace Hub\Device;

use Hub\Protocol\AdapterRegistry;

class DeviceIdentityExtractor
{
    private AdapterRegistry $adapters;

    public function __construct(?AdapterRegistry $adapters = null)
    {
        $this->adapters = $adapters ?? new AdapterRegistry();
    }

    public function identify(string $raw, array $session = []): ?DeviceIdentity
    {
        $decoded = $this->adapters->decodeAny($raw, ['session' => $session]);
        if (!is_array($decoded)) {
            return null;
        }

        $imei = (string)($decoded['imei'] ?? '');
        if ($imei === '' && isset($decoded['data']) && is_array($decoded['data'])) {
            $imei = (string)($decoded['data']['imei'] ?? '');
        }
        if ($imei === '') {
            return null;
        }

        $data = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : [];

        return new DeviceIdentity(
            imei: $imei,
            protocol: (string)($decoded['_protocol'] ?? ''),
            model: (string)($data['deviceModel'] ?? ''),
            ident: (string)($decoded['ident'] ?? ''),
        );
    }
}
