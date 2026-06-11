<?php

namespace App\Hub;

use App\Registry\Whitelist;

class DeviceAuthorizer
{
    private Whitelist $whitelist;

    public function __construct(Whitelist $whitelist)
    {
        $this->whitelist = $whitelist;
    }

    public function authorize(DeviceIdentity $identity): AuthorizationResult
    {
        if (!$this->whitelist->isAuthorized($identity->imei)) {
            return AuthorizationResult::deny('device_not_authorized');
        }

        $metadata = $this->metadataFor($identity->imei);

        return AuthorizationResult::allow($metadata['supplier'], $metadata['model']);
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
