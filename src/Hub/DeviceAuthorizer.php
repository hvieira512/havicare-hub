<?php

namespace Hub;

use Hub\Domain\DeviceMetadata;

use Hub\Registry\Whitelist;

class DeviceAuthorizer
{
    private Whitelist $whitelist;
    private ?CommercialModelResolver $commercialModelResolver;

    public function __construct(Whitelist $whitelist, ?CommercialModelResolver $commercialModelResolver = null)
    {
        $this->whitelist = $whitelist;
        $this->commercialModelResolver = $commercialModelResolver;
    }

    public function authorize(DeviceIdentity $identity): AuthorizationResult
    {
        $resolved = $this->whitelist->resolve($identity->imei, $identity->protocol, $identity->ident);
        if ($resolved === null) {
            return AuthorizationResult::deny('device_not_authorized');
        }

        $commercialName = $this->commercialNameFor((string)$resolved['supplier'], (string)$resolved['model']);

        return AuthorizationResult::allow(
            imei: (string)$resolved['imei'],
            supplier: (string)$resolved['supplier'],
            model: (string)$resolved['model'],
            commercialName: $commercialName,
            deviceType: (string)($resolved['deviceType'] ?? 'watch'),
            licenseId: DeviceMetadata::normalizeLicenseId($resolved['licenseId'] ?? 0),
            company: (string)($resolved['company'] ?? 'null'),
        );
    }

    /**
     * @return array{supplier: string, model: string, commercialName: string, deviceType: string, licenseId: int, company: string}
     */
    public function metadataFor(string $imei): array
    {
        $metadata = $this->whitelist->getMetadata($imei) ?? [];
        $supplier = (string)($metadata['supplier'] ?? '');
        $model = (string)($metadata['model'] ?? '');

        return [
            'supplier' => $supplier,
            'model' => $model,
            'commercialName' => $this->commercialNameFor($supplier, $model),
            'deviceType' => (string)($metadata['deviceType'] ?? 'watch'),
            'licenseId' => DeviceMetadata::normalizeLicenseId($metadata['licenseId'] ?? 0),
            'company' => (string)($metadata['company'] ?? 'null'),
        ];
    }

    private function commercialNameFor(string $supplier, string $model): string
    {
        return $this->commercialModelResolver?->resolveCommercialName($supplier, $model) ?? '';
    }
}
