<?php

namespace Hub\Registry;

use Hub\Dashboard\DatabaseStore;

class Whitelist
{
    /** @var array<string, array{supplier: string, model: string, simNumber: string}> */
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
        if ($this->db !== null) {
            $this->devices = [];
            foreach ($this->db->whitelistAll() as $row) {
                $imei = (string)$row['imei'];
                $supplier = (string)$row['supplier'];
                $model = (string)$row['model'];
                $simNumber = (string)($row['sim_number'] ?? '');
                if ($imei !== '' && $supplier !== '' && $model !== '') {
                    $this->devices[$imei] = ['supplier' => $supplier, 'model' => $model, 'simNumber' => $simNumber];
                }
            }
            return;
        }

        $this->devices = [];
        if (!file_exists($this->filePath)) {
            return;
        }
        $raw = json_decode(file_get_contents($this->filePath), true) ?? [];
        foreach ($raw as $imei => $value) {
            if (!is_scalar($imei) || !is_array($value)) {
                continue;
            }
            $imei = trim((string)$imei);
            $supplier = trim((string)($value['supplier'] ?? ''));
            $model = trim((string)($value['model'] ?? ''));
            $simNumber = trim((string)($value['simNumber'] ?? $value['sim_number'] ?? ''));
            if ($imei === '' || $supplier === '' || $model === '') {
                continue;
            }
            $this->devices[$imei] = ['supplier' => $supplier, 'model' => $model, 'simNumber' => $simNumber];
        }
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

    public function register(string $imei, string $supplier, string $model, string $simNumber = ''): void
    {
        $this->devices[$imei] = ['supplier' => $supplier, 'model' => $model, 'simNumber' => $simNumber];
        $this->db?->whitelistRegister($imei, $supplier, $model, $simNumber);
        $this->saveFile();
    }

    public function unregister(string $imei): void
    {
        unset($this->devices[$imei]);
        $this->db?->whitelistUnregister($imei);
        $this->saveFile();
    }

    public function update(string $imei, string $supplier, string $model, string $simNumber = ''): bool
    {
        if (!isset($this->devices[$imei])) {
            return false;
        }
        $this->devices[$imei] = ['supplier' => $supplier, 'model' => $model, 'simNumber' => $simNumber];
        $this->db?->whitelistRegister($imei, $supplier, $model, $simNumber);
        $this->saveFile();
        return true;
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
