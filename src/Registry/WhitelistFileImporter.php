<?php

declare(strict_types=1);

namespace Hub\Registry;

use Hub\Api\Repository\WhitelistRepository;
use Hub\Domain\DeviceMetadata;

final class WhitelistFileImporter
{
    public function __construct(private WhitelistRepository $repository)
    {
    }

    /** @return array{imported: int, skipped: int} */
    public function import(string $filePath): array
    {
        $contents = file_get_contents($filePath);
        if (!is_string($contents)) {
            throw new \RuntimeException("Unable to read whitelist file: {$filePath}");
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Whitelist file must contain a JSON object');
        }

        $imported = 0;
        $skipped = 0;
        foreach ($decoded as $imei => $value) {
            if (!is_scalar($imei) || !is_array($value)) {
                $skipped++;
                continue;
            }

            $imei = trim((string)$imei);
            $supplier = trim((string)($value['supplier'] ?? ''));
            $model = trim((string)($value['model'] ?? ''));
            if ($imei === '' || $supplier === '' || $model === '') {
                $skipped++;
                continue;
            }

            $this->repository->register(
                $imei,
                $supplier,
                $model,
                DeviceMetadata::normalizeDeviceType((string)($value['deviceType'] ?? $value['device_type'] ?? 'watch')),
                DeviceMetadata::normalizeLicenseId((string)($value['licenseId'] ?? $value['license_id'] ?? '0')),
                trim((string)($value['simNumber'] ?? $value['sim_number'] ?? '')),
                trim((string)($value['deviceId'] ?? $value['device_id'] ?? $value['sourceDeviceId'] ?? '')),
                trim((string)($value['company'] ?? 'null')),
            );
            $imported++;
        }

        return ['imported' => $imported, 'skipped' => $skipped];
    }
}
