<?php

declare(strict_types=1);

namespace Hub\Api\Http;

use Hub\Domain\DeviceMetadata;

final class DevicePresentation
{
    /**
     * O dono como a API o diz: sem empresa nem licença é `null`. O `'null'` e o `0` são
     * sentinelas de dentro do hub.
     *
     * @return array{company: string|null, licenseId: int|null}
     */
    public static function owner(mixed $company, mixed $licenseId): array
    {
        $company = DeviceMetadata::normalizeCompany(is_string($company) ? $company : null);
        $licenseId = DeviceMetadata::normalizeLicenseId(is_int($licenseId) || is_string($licenseId) ? $licenseId : 0);

        return [
            'company' => $company === 'null' ? null : $company,
            'licenseId' => $licenseId === 0 ? null : $licenseId,
        ];
    }

    /**
     * @param array<string, mixed> $device
     * @return array<string, mixed>
     */
    public static function withOwner(array $device): array
    {
        return self::owner($device['company'] ?? null, $device['licenseId'] ?? null) + $device;
    }
}
