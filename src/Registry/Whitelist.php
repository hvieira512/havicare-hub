<?php

namespace App\Registry;

class Whitelist
{
    private array $devices;
    private string $filePath;

    public function __construct(?string $filePath = null)
    {
        $this->filePath = $filePath ?? __DIR__ . '/../../config/whitelist.json';
        $this->loadFromFile();
    }

    private function loadFromFile(): void
    {
        if (!file_exists($this->filePath)) {
            $this->devices = [];
            return;
        }

        $raw = json_decode(file_get_contents($this->filePath), true) ?? [];
        $this->devices = [];
        foreach ($raw as $imei => $value) {
            if (is_array($value)) {
                $entry = [
                    'model' => $value['model'] ?? '',
                    'enabled' => $value['enabled'] ?? true,
                    'registered_at' => $value['registered_at'] ?? null,
                ];
                if (isset($value['key']) && is_string($value['key'])) {
                    $entry['key'] = $value['key'];
                }
                $this->devices[$imei] = $entry;
            } else {
                $this->devices[$imei] = [
                    'model' => (string)$value,
                    'enabled' => true,
                    'registered_at' => null,
                ];
            }
        }
    }

    public function isAuthorized(string $imei): bool
    {
        return isset($this->devices[$imei]) && $this->devices[$imei]['enabled'] === true;
    }

    public function getModel(string $imei): ?string
    {
        return $this->devices[$imei]['model'] ?? null;
    }

    public function all(): array
    {
        return $this->devices;
    }

    public function getDeviceSecret(string $imei): ?string
    {
        $entry = $this->devices[$imei] ?? null;
        if (is_array($entry) && isset($entry['key']) && is_string($entry['key']) && $entry['key'] !== '') {
            return $entry['key'];
        }

        $envKey = 'DEVICE_SECRET_' . strtoupper($imei);
        $env = getenv($envKey);
        if (is_string($env) && $env !== '') {
            return $env;
        }

        return null;
    }

    public function register(string $imei, string $model, bool $enabled = true): void
    {
        $data = [
            'imei' => $imei,
            'model' => $model,
            'enabled' => $enabled,
            'registered_at' => date('c'),
        ];

        $this->devices[$imei] = [
            'model' => $model,
            'enabled' => $enabled,
            'registered_at' => date('c'),
        ];

        $this->saveFile();
    }

    public function unregister(string $imei): void
    {
        unset($this->devices[$imei]);

        $this->saveFile();
    }

    public function update(string $imei, array $data): bool
    {
        if (!isset($this->devices[$imei])) {
            return false;
        }

        if (isset($data['model'])) {
            $this->devices[$imei]['model'] = $data['model'];
        }
        if (isset($data['enabled'])) {
            $this->devices[$imei]['enabled'] = (bool)$data['enabled'];
        }

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
