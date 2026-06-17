<?php

namespace Hub;

use Hub\Registry\Whitelist;

class DeviceAuthorizer
{
    private Whitelist $whitelist;

    public function __construct(Whitelist $whitelist)
    {
        $this->whitelist = $whitelist;
    }

    public function authorize(DeviceIdentity $identity): AuthorizationResult
    {
        $resolved = $this->whitelist->resolve($identity->imei, $identity->protocol, $identity->ident);
        if ($resolved === null) {
            return AuthorizationResult::deny('device_not_authorized');
        }

        return AuthorizationResult::allow(
            (string)$resolved['imei'],
            (string)$resolved['supplier'],
            (string)$resolved['model'],
        );
    }

    /**
     * @return array{supplier: string, model: string}
     */
    public function metadataFor(string $imei): array
    {
        $metadata = $this->whitelist->getMetadata($imei) ?? [];

        return [
            'supplier' => (string)($metadata['supplier'] ?? ''),
            'model' => (string)($metadata['model'] ?? ''),
        ];
    }
}
