<?php

namespace App\Registry;

use App\Log\Logger;
use App\Repository\ModelRepository;

class DeviceCapabilities
{
    private static ?array $profiles = null;
    private static ?string $profilesPath = null;
    private static ?\PDO $pdo = null;
    private static int $cacheTtlSeconds = 5;
    private static int $lastLoadedAt = 0;

    public static function setProfilesPath(string $path): void
    {
        self::$profiles = null;
        self::$lastLoadedAt = 0;
        self::$profilesPath = $path;
    }

    public static function setDatabasePdo(?\PDO $pdo): void
    {
        self::$pdo = $pdo;
        self::$profiles = null;
        self::$lastLoadedAt = 0;
    }

    public static function setCacheTtl(int $seconds): void
    {
        self::$cacheTtlSeconds = max(1, $seconds);
    }

    private static function load(): void
    {
        $now = time();
        if (self::$profiles !== null && ($now - self::$lastLoadedAt) < self::$cacheTtlSeconds) {
            return;
        }

        $profiles = self::loadFromDatabase();
        if ($profiles === null || $profiles === []) {
            if ($profiles === []) {
                Logger::channel('capabilities')->warning('Model catalog in MySQL is empty; falling back to capabilities.json');
            }
            $profiles = self::loadFromJson();
        }

        self::$profiles = $profiles;
        self::$lastLoadedAt = $now;
    }

    private static function loadFromDatabase(): ?array
    {
        if (self::$pdo === null) {
            return null;
        }

        try {
            $repo = new ModelRepository(self::$pdo);
            $rows = $repo->allProfiles();
            $profiles = [];
            foreach ($rows as $row) {
                $profiles[$row['code']] = [
                    'name' => $row['name'],
                    'supplier' => $row['supplier_name'],
                    'protocol' => $row['protocol'],
                    'transport' => $row['transport'],
                    'enabled' => (bool)$row['enabled'],
                    'passive' => $row['passive'],
                    'active' => $row['active'],
                    'features' => $row['features'],
                    'native_mappings' => $row['native_mappings'] ?? [],
                    'command_metadata' => $row['command_metadata'] ?? [],
                ];
            }
            return $profiles;
        } catch (\Throwable $e) {
            Logger::channel('capabilities')->warning('Failed to load capabilities from MySQL: ' . $e->getMessage());
            return null;
        }
    }

    private static function loadFromJson(): array
    {
        $path = self::$profilesPath ?? __DIR__ . '/../../config/capabilities.json';

        if (!file_exists($path)) {
            return [];
        }

        $decoded = json_decode(file_get_contents($path), true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    public static function forModel(string $model): ?self
    {
        self::load();
        if (!isset(self::$profiles[$model])) {
            return null;
        }
        return new self($model, self::$profiles[$model]);
    }

    public static function allModels(): array
    {
        self::load();
        return array_keys(self::$profiles);
    }

    public static function modelName(string $model): string
    {
        self::load();
        $profile = self::$profiles[$model] ?? null;
        if (!is_array($profile)) {
            return $model;
        }

        $name = $profile['name'] ?? $model;
        return is_string($name) && $name !== '' ? $name : $model;
    }

    // --- Instance ---

    private string $model;
    private string $name;
    private ?string $supplier;
    private ?string $protocol;
    private ?string $transport;
    private bool $enabled;
    private array $passive;
    private array $active;
    private array $features;
    private array $commandMetadata;
    private array $nativeMappings;

    private function __construct(string $model, array $profile)
    {
        $this->model = $model;
        $this->name = $profile['name'] ?? $model;
        $this->supplier = $profile['supplier'] ?? null;
        $this->protocol = $profile['protocol'] ?? null;
        $this->transport = $profile['transport'] ?? null;
        $this->enabled = (bool)($profile['enabled'] ?? true);
        $this->passive = $profile['passive'] ?? [];
        $this->active = $profile['active'] ?? [];
        $this->features = $profile['features'] ?? [];
        $this->commandMetadata = $this->normalizeCommandMetadata($profile['command_metadata'] ?? []);
        $this->nativeMappings = $this->normalizeNativeMappings($profile['native_mappings'] ?? []);
    }

    public function getSupplier(): ?string
    {
        return $this->supplier;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getProtocol(): ?string
    {
        return $this->protocol;
    }

    public function getTransport(): ?string
    {
        return $this->transport;
    }

    public function supportsPassive(string $type): bool
    {
        return in_array($type, $this->passive, true);
    }

    public function supportsActive(string $type): bool
    {
        return in_array($type, $this->active, true);
    }

    public function supportsFeature(string $feature): bool
    {
        return isset($this->features[$feature]);
    }

    public function getFeatures(): array
    {
        return $this->features;
    }

    public function getFeatureNames(): array
    {
        $names = array_keys($this->features);
        sort($names);
        return $names;
    }

    public function featureForPassive(string $type): ?string
    {
        foreach ($this->features as $feature => $commands) {
            if (in_array($type, $commands['passive'] ?? [], true)) {
                return $feature;
            }
        }

        return null;
    }

    public function resolveFeatureActiveCommand(string $feature): ?string
    {
        $commands = $this->features[$feature]['active'] ?? [];
        foreach ($commands as $command) {
            if ($this->supportsActive($command)) {
                return $command;
            }
        }

        return null;
    }

    public function getPassive(): array
    {
        return $this->passive;
    }

    public function getCommandMetadata(?string $type = null): array
    {
        if ($type === null) {
            return $this->commandMetadata;
        }

        return $this->commandMetadata[$type] ?? [];
    }

    public function getNativeMappings(): array
    {
        if ($this->nativeMappings !== []) {
            return $this->nativeMappings;
        }

        $fallback = [];
        foreach ($this->features as $feature => $commands) {
            foreach (($commands['passive'] ?? []) as $type) {
                $fallback[] = [
                    'nativeType' => (string)$type,
                    'feature' => (string)$feature,
                    'isActive' => false,
                    'triggerMode' => 'both',
                    'isTransportAck' => false,
                    'isBusinessReply' => false,
                    'notes' => null,
                ];
            }
            foreach (($commands['active'] ?? []) as $type) {
                $fallback[] = [
                    'nativeType' => (string)$type,
                    'feature' => (string)$feature,
                    'isActive' => true,
                    'triggerMode' => 'reply',
                    'isTransportAck' => false,
                    'isBusinessReply' => true,
                    'notes' => null,
                ];
            }
        }

        usort($fallback, static fn(array $a, array $b): int => strcmp($a['nativeType'], $b['nativeType']));
        return $fallback;
    }

    public function featureForActive(string $type): ?string
    {
        foreach ($this->features as $feature => $commands) {
            if (in_array($type, $commands['active'] ?? [], true)) {
                return $feature;
            }
        }

        return null;
    }

    public function getActive(): array
    {
        return $this->active;
    }

    public function toArray(): array
    {
        return [
            'supplier' => $this->supplier,
            'protocol' => $this->protocol,
            'transport' => $this->transport,
            'enabled' => $this->enabled,
            'passive' => $this->passive,
            'active'  => $this->active,
            'features' => $this->features,
            'command_metadata' => $this->commandMetadata,
            'native_mappings' => $this->nativeMappings,
        ];
    }

    private function normalizeCommandMetadata(array $metadata): array
    {
        $normalized = [];
        $allTypes = array_values(array_unique(array_merge($this->passive, $this->active)));

        foreach ($allTypes as $type) {
            $raw = $metadata[$type] ?? [];
            $feature = $this->featureForPassive($type) ?? $this->featureForActive($type);
            $direction = in_array($type, $this->active, true) ? 'active' : 'passive';
            $replyTypes = $this->resolveExpectedReplyTypes($type, $raw['expectedReplyTypes'] ?? null);

            $normalized[$type] = [
                'type' => $type,
                'title' => (string)($raw['title'] ?? $type),
                'description' => (string)($raw['description'] ?? 'Protocol command'),
                'feature' => (string)($raw['feature'] ?? ($feature ?? '')),
                'direction' => (string)($raw['direction'] ?? $direction),
                'expectedReplyTypes' => $replyTypes,
                'riskLevel' => (string)($raw['riskLevel'] ?? $this->inferRiskLevel($feature, $type)),
                'notes' => (string)($raw['notes'] ?? ''),
            ];
        }

        ksort($normalized);
        return $normalized;
    }

    private function resolveExpectedReplyTypes(string $type, mixed $configured): array
    {
        if (is_array($configured)) {
            return array_values(array_filter(array_map(
                static fn(mixed $value): string => is_scalar($value) ? trim((string)$value) : '',
                $configured
            )));
        }

        if (preg_match('/^BP([A-Z0-9]{2})$/', $type, $match) === 1) {
            return ['AP' . $match[1]];
        }

        return [];
    }

    private function inferRiskLevel(?string $feature, string $type): string
    {
        if ($feature !== null && in_array($feature, ['factory_reset', 'power_off', 'restart'], true)) {
            return 'high';
        }
        if (in_array($type, ['BP17', 'restart', 'powerOff', 'reset'], true)) {
            return 'high';
        }

        return 'normal';
    }

    private function normalizeNativeMappings(array $mappings): array
    {
        $normalized = [];
        foreach ($mappings as $mapping) {
            if (!is_array($mapping)) {
                continue;
            }
            $nativeType = trim((string)($mapping['nativeType'] ?? $mapping['native_type'] ?? ''));
            if ($nativeType === '') {
                continue;
            }
            $normalized[] = [
                'nativeType' => $nativeType,
                'feature' => isset($mapping['feature']) ? (string)$mapping['feature'] : null,
                'isActive' => (bool)($mapping['isActive'] ?? $mapping['is_active'] ?? false),
                'triggerMode' => (string)($mapping['triggerMode'] ?? $mapping['trigger_mode'] ?? 'both'),
                'isTransportAck' => (bool)($mapping['isTransportAck'] ?? $mapping['is_transport_ack'] ?? false),
                'isBusinessReply' => (bool)($mapping['isBusinessReply'] ?? $mapping['is_business_reply'] ?? false),
                'notes' => isset($mapping['notes']) ? (string)$mapping['notes'] : null,
            ];
        }

        usort($normalized, static fn(array $a, array $b): int => strcmp($a['nativeType'], $b['nativeType']));
        return $normalized;
    }
}
