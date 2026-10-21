<?php

declare(strict_types=1);

namespace Hub\Api\Http;

use Hub\Domain\DeviceMetadata;

final class DevicePresentation
{
    public function __construct(private ModelImageUrl $images = new ModelImageUrl())
    {
    }

    /**
     * @param array<string, mixed> $device
     * @param array<string, mixed>|null $modelRow
     * @return array<string, mixed>
     */
    public function attachImage(array $device, ?array $modelRow, string $baseUrl): array
    {
        $device['image'] = $this->modelImage($modelRow, $baseUrl);

        return $device;
    }

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

    /** @param array<string, mixed>|null $modelRow */
    public function modelImage(?array $modelRow, string $baseUrl): ?string
    {
        if ($modelRow === null) {
            return null;
        }

        return $this->images->resolve((string)($modelRow['image_path'] ?? ''), $baseUrl);
    }
}
