<?php

namespace Hub\Registry;

use Hub\Dashboard\DatabaseStore;

class Whitelist
{
    /** @var array<string, array{supplier: string, model: string, deviceType: string, licenseId: string, simNumber: string, deviceId: string}> */
    private array $devices;
    private string $filePath;
    private ?DatabaseStore $db;

    public function __construct(?string $filePath = null, ?DatabaseStore $db = null)
    {
        $this->filePath = $filePath ?? __DIR__ . '/../../config/whitelist.json';
        $this->db = $db;
        $this->load();
    }

    private function load(): void
    {
        $this->devices = [];

        if ($this->db !== null) {
            foreach ($this->db->whitelistAll() as $row) {
                $this->loadEntry((string)$row['imei'], $row);
            }
        }

        if (file_exists($this->filePath)) {
            $raw = json_decode(file_get_contents($this->filePath), true) ?? [];
            foreach ($raw as $imei => $value) {
                if (!is_scalar($imei) || !is_array($value) || isset($this->devices[trim((string)$imei)])) {
                    continue;
                }
                $this->loadEntry((string)$imei, $value);
            }
        }
    }

    private function loadEntry(string $imei, array $value): void
    {
        $imei = trim($imei);
        $supplier = trim((string)($value['supplier'] ?? ''));
        $model = trim((string)($value['model'] ?? ''));
        $deviceType = DatabaseStore::normalizeDeviceType((string)($value['deviceType'] ?? $value['device_type'] ?? 'watch'));
        $licenseId = DatabaseStore::normalizeLicenseId((string)($value['licenseId'] ?? $value['license_id'] ?? '0'));
        $simNumber = trim((string)($value['simNumber'] ?? $value['sim_number'] ?? ''));
        $deviceId = trim((string)($value['deviceId'] ?? $value['device_id'] ?? ''));
        if ($imei === '' || $supplier === '' || $model === '') {
            return;
        }

        $this->devices[$imei] = [
            'supplier' => $supplier,
            'model' => $model,
            'deviceType' => $deviceType,
            'licenseId' => $licenseId,
            'simNumber' => $simNumber,
            'deviceId' => $deviceId,
        ];
    }

    public function isAuthorized(string $imei): bool
    {
        return isset($this->devices[$imei]);
    }

    public function getModel(string $imei): ?string
    {
        return $this->devices[$imei]['model'] ?? null;
    }

    public function getSupplier(string $imei): ?string
    {
        return $this->devices[$imei]['supplier'] ?? null;
    }

    public function getMetadata(string $imei): ?array
    {
        return $this->devices[$imei] ?? null;
    }

    public function all(): array
    {
        return $this->devices;
    }

    public function register(
        string $imei,
        string $supplier,
        string $model,
        string $deviceType = 'watch',
        string $licenseId = '0',
        string $simNumber = '',
        string $deviceId = ''
    ): void
    {
        $deviceType = DatabaseStore::normalizeDeviceType($deviceType);
        $licenseId = DatabaseStore::normalizeLicenseId($licenseId);
        $this->devices[$imei] = [
            'supplier' => $supplier,
            'model' => $model,
            'deviceType' => $deviceType,
            'licenseId' => $licenseId,
            'simNumber' => $simNumber,
            'deviceId' => $deviceId,
        ];
        $this->db?->whitelistRegister($imei, $supplier, $model, $deviceType, $licenseId, $simNumber, $deviceId);
        $this->saveFile();
    }

    public function unregister(string $imei): void
    {
        unset($this->devices[$imei]);
        $this->db?->whitelistUnregister($imei);
        $this->saveFile();
    }

    public function update(
        string $imei,
        string $supplier,
        string $model,
        string $deviceType = 'watch',
        string $licenseId = '0',
        string $simNumber = '',
        string $deviceId = ''
    ): bool
    {
        if (!isset($this->devices[$imei])) {
            return false;
        }
        $deviceType = DatabaseStore::normalizeDeviceType($deviceType);
        $licenseId = DatabaseStore::normalizeLicenseId($licenseId);
        $this->devices[$imei] = [
            'supplier' => $supplier,
            'model' => $model,
            'deviceType' => $deviceType,
            'licenseId' => $licenseId,
            'simNumber' => $simNumber,
            'deviceId' => $deviceId,
        ];
        $this->db?->whitelistRegister($imei, $supplier, $model, $deviceType, $licenseId, $simNumber, $deviceId);
        $this->saveFile();
        return true;
    }

    /**
     * @return array{imei: string, supplier: string, model: string, deviceType: string, licenseId: string, simNumber: string, deviceId: string}|null
     */
    public function resolve(string $imei, string $protocol = '', string $ident = ''): ?array
    {
        $exact = $this->getMetadata($imei);
        if ($exact !== null) {
            return ['imei' => $imei] + $exact;
        }

        if ($protocol !== 'four-p-touch') {
            return null;
        }

        $alias = trim($ident !== '' ? $ident : $imei);
        if ($alias === '') {
            return null;
        }

        foreach ($this->devices as $canonicalImei => $metadata) {
            if (($metadata['deviceId'] ?? '') !== $alias) {
                continue;
            }

            return ['imei' => $canonicalImei] + $metadata;
        }

        return null;
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
