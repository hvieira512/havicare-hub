<?php

namespace Hub\Registry;

use Hub\Domain\DeviceMetadata;
use Hub\Api\Repository\WhitelistRepository;

class Whitelist
{
    /** @var array<string, array{supplier: string, model: string, deviceType: string, licenseId: string, simNumber: string, deviceId: string, company: string}> */
    private array $devices;
    private string $filePath;
    private ?WhitelistRepository $db;
    private int $databaseCacheLoadedAt = 0;

    public function __construct(
        ?string $filePath = null,
        ?WhitelistRepository $db = null,
        private int $databaseCacheTtlSeconds = 5,
    ) {
        $this->filePath = $filePath ?? __DIR__ . '/../../config/whitelist.json';
        $this->db = $db;
        $this->databaseCacheTtlSeconds = max(0, $this->databaseCacheTtlSeconds);
        $this->load();
    }

    private function load(): void
    {
        $this->devices = [];

        if ($this->db !== null) {
            $this->loadDatabaseCache();
            return;
        }

        if (file_exists($this->filePath)) {
            $raw = json_decode(file_get_contents($this->filePath), true) ?? [];
            foreach ($raw as $imei => $value) {
                if (!is_scalar($imei) || !is_array($value) || isset($this->devices[trim((string)$imei)])) {
                    continue;
                }
                $this->loadEntry((string)$imei, $value);
                if ($this->db !== null) {
                    $metadata = $this->devices[trim((string)$imei)] ?? null;
                    if (is_array($metadata)) {
                        $this->db->register(
                            trim((string)$imei),
                            (string)($metadata['supplier'] ?? ''),
                            (string)($metadata['model'] ?? ''),
                            (string)($metadata['deviceType'] ?? 'watch'),
                            DeviceMetadata::normalizeLicenseId($metadata['licenseId'] ?? 0),
                            (string)($metadata['simNumber'] ?? ''),
                            (string)($metadata['deviceId'] ?? ''),
                            (string)($metadata['company'] ?? 'null'),
                        );
                    }
                }
            }
        }
    }

    private function loadEntry(string $imei, array $value): void
    {
        $imei = trim($imei);
        $supplier = trim((string)($value['supplier'] ?? ''));
        $model = trim((string)($value['model'] ?? ''));
        $deviceType = DeviceMetadata::normalizeDeviceType((string)($value['deviceType'] ?? $value['device_type'] ?? 'watch'));
        $licenseId = DeviceMetadata::normalizeLicenseId((string)($value['licenseId'] ?? $value['license_id'] ?? '0'));
        $company = DeviceMetadata::normalizeCompany((string)($value['company'] ?? 'null'));
        $simNumber = trim((string)($value['simNumber'] ?? $value['sim_number'] ?? ''));
        $deviceId = trim((string)($value['deviceId'] ?? $value['device_id'] ?? ''));
        if ($deviceId === '') {
            $deviceId = trim((string)($value['sourceDeviceId'] ?? $value['source_device_id'] ?? ''));
        }
        if ($imei === '' || $supplier === '' || $model === '') {
            return;
        }

        $this->devices[$imei] = [
            'supplier' => $supplier,
            'model' => $model,
            'deviceType' => $deviceType,
            'licenseId' => $licenseId,
            'company' => $company,
            'simNumber' => $simNumber,
            'deviceId' => $deviceId,
        ];
    }

    public function isAuthorized(string $imei): bool
    {
        return $this->getMetadata($imei) !== null;
    }

    public function getMetadata(string $imei): ?array
    {
        if ($this->db !== null) {
            $this->refreshDatabaseCacheIfStale();
        }

        return $this->devices[$imei] ?? null;
    }

    public function all(): array
    {
        if ($this->db !== null) {
            $this->refreshDatabaseCacheIfStale();
        }

        return $this->devices;
    }

    public function register(
        string $imei,
        string $supplier,
        string $model,
        string $deviceType = 'watch',
        int|string $licenseId = 0,
        string $simNumber = '',
        string $deviceId = '',
        string $company = 'null',
    ): void {
        $deviceType = DeviceMetadata::normalizeDeviceType($deviceType);
        $licenseId = DeviceMetadata::normalizeLicenseId($licenseId);
        $company = DeviceMetadata::normalizeCompany($company);
        $this->devices[$imei] = [
            'supplier' => $supplier,
            'model' => $model,
            'deviceType' => $deviceType,
            'licenseId' => $licenseId,
            'company' => $company,
            'simNumber' => $simNumber,
            'deviceId' => $deviceId,
        ];
        $this->db?->register($imei, $supplier, $model, $deviceType, $licenseId, $simNumber, $deviceId, $company);
        if ($this->db === null) {
            $this->saveFile();
        }
    }

    public function unregister(string $imei): void
    {
        unset($this->devices[$imei]);
        $this->db?->unregister($imei);
        if ($this->db === null) {
            $this->saveFile();
        }
    }

    public function update(
        string $imei,
        string $supplier,
        string $model,
        string $deviceType = 'watch',
        int|string $licenseId = 0,
        string $simNumber = '',
        string $deviceId = '',
        string $company = 'null',
    ): bool {
        if ($this->getMetadata($imei) === null) {
            return false;
        }
        $deviceType = DeviceMetadata::normalizeDeviceType($deviceType);
        $licenseId = DeviceMetadata::normalizeLicenseId($licenseId);
        $company = DeviceMetadata::normalizeCompany($company);
        $this->devices[$imei] = [
            'supplier' => $supplier,
            'model' => $model,
            'deviceType' => $deviceType,
            'licenseId' => $licenseId,
            'company' => $company,
            'simNumber' => $simNumber,
            'deviceId' => $deviceId,
        ];
        $this->db?->register($imei, $supplier, $model, $deviceType, $licenseId, $simNumber, $deviceId, $company);
        if ($this->db === null) {
            $this->saveFile();
        }
        return true;
    }

    public function updateAssociation(string $imei, string $company, int|string $licenseId): bool
    {
        if ($this->getMetadata($imei) === null) {
            return false;
        }

        $company = DeviceMetadata::normalizeCompany($company);
        $licenseId = DeviceMetadata::normalizeLicenseId($licenseId);
        $this->devices[$imei]['company'] = $company;
        $this->devices[$imei]['licenseId'] = $licenseId;
        $this->db?->updateAssociation($imei, $company, $licenseId);
        if ($this->db === null) {
            $this->saveFile();
        }

        return true;
    }

    /**
     * @return array{imei: string, supplier: string, model: string, deviceType: string, licenseId: string, company: string, simNumber: string, deviceId: string}|null
     */
    public function resolve(string $imei, string $protocol = '', string $ident = ''): ?array
    {
        $exact = $this->getMetadata($imei);
        if ($exact !== null) {
            return ['imei' => $imei] + $exact;
        }

        $alias = trim($ident !== '' ? $ident : $imei);
        if ($alias === '') {
            return null;
        }

        if ($protocol === 'four-p-touch') {
            if ($this->db !== null) {
                return $this->resolvedDatabaseAlias($this->db->findByDeviceId($alias));
            }
            foreach ($this->devices as $canonicalImei => $metadata) {
                if (($metadata['deviceId'] ?? '') !== $alias) {
                    continue;
                }

                return ['imei' => $canonicalImei] + $metadata;
            }

            return null;
        }

        if ($protocol === 'ncs') {
            if ($this->db !== null) {
                return $this->resolvedDatabaseAlias($this->db->findByDeviceId($alias, 'ncs'));
            }
            foreach ($this->devices as $canonicalImei => $metadata) {
                if (($metadata['deviceType'] ?? '') !== 'ncs') {
                    continue;
                }

                if (($metadata['deviceId'] ?? '') !== $alias) {
                    continue;
                }

                return ['imei' => $canonicalImei] + $metadata;
            }

            return null;
        }

        if ($protocol === 'qinglanst-radar' || $protocol === 'qinglanst') {
            if ($this->db !== null) {
                return $this->resolvedDatabaseAlias($this->db->findByDeviceId($alias, 'radar'));
            }
            foreach ($this->devices as $canonicalImei => $metadata) {
                if (($metadata['deviceType'] ?? '') !== 'radar') {
                    continue;
                }

                if ($canonicalImei === $alias || ($metadata['deviceId'] ?? '') === $alias) {
                    return ['imei' => $canonicalImei] + $metadata;
                }
            }

            return null;
        }

        return null;
    }

    private function normalizeMetadata(array $value): array
    {
        $deviceId = trim((string)($value['deviceId'] ?? $value['device_id'] ?? ''));
        if ($deviceId === '') {
            $deviceId = trim((string)($value['sourceDeviceId'] ?? $value['source_device_id'] ?? ''));
        }

        return [
            'supplier' => trim((string)($value['supplier'] ?? '')),
            'model' => trim((string)($value['model'] ?? '')),
            'deviceType' => DeviceMetadata::normalizeDeviceType((string)($value['deviceType'] ?? $value['device_type'] ?? 'watch')),
            'licenseId' => DeviceMetadata::normalizeLicenseId((string)($value['licenseId'] ?? $value['license_id'] ?? '0')),
            'company' => DeviceMetadata::normalizeCompany((string)($value['company'] ?? 'null')),
            'simNumber' => trim((string)($value['simNumber'] ?? $value['sim_number'] ?? '')),
            'deviceId' => $deviceId,
        ];
    }

    private function refreshDatabaseCacheIfStale(): void
    {
        if (
            $this->db !== null
            && ($this->databaseCacheTtlSeconds === 0
                || (time() - $this->databaseCacheLoadedAt) >= $this->databaseCacheTtlSeconds)
        ) {
            $this->devices = [];
            $this->loadDatabaseCache();
        }
    }

    private function loadDatabaseCache(): void
    {
        if ($this->db === null) {
            return;
        }

        foreach ($this->db->all() as $row) {
            $this->loadEntry((string)$row['imei'], $row);
        }
        $this->databaseCacheLoadedAt = time();
    }

    private function resolvedDatabaseAlias(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        $imei = trim((string)($row['imei'] ?? ''));
        return $imei === '' ? null : ['imei' => $imei] + $this->normalizeMetadata($row);
    }

    private function saveFile(): void
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            $this->filePath,
            json_encode($this->devices, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }
}
