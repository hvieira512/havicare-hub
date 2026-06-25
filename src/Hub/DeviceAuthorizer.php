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
            imei: (string)$resolved['imei'],
            supplier: (string)$resolved['supplier'],
            model: (string)$resolved['model'],
            deviceType: (string)($resolved['deviceType'] ?? 'watch'),
            licenseId: (string)($resolved['licenseId'] ?? '0'),
            company: (string)($resolved['company'] ?? 'null'),
        );
    }

    /**
     * @return array{supplier: string, model: string, deviceType: string, licenseId: string, company: string}
     */
    public function metadataFor(string $imei): array
    {
        $metadata = $this->whitelist->getMetadata($imei) ?? [];

        return [
            'supplier' => (string)($metadata['supplier'] ?? ''),
            'model' => (string)($metadata['model'] ?? ''),
            'deviceType' => (string)($metadata['deviceType'] ?? 'watch'),
            'licenseId' => (string)($metadata['licenseId'] ?? '0'),
            'company' => (string)($metadata['company'] ?? 'null'),
        ];
    }
}
