<?php

declare(strict_types=1);

namespace Hub\Domain\Capability;

use Hub\Command\DeviceCommandCatalog;
use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\Definition\NcsCapabilityDefinitions;
use Hub\Domain\Capability\Definition\RadarCapabilityDefinitions;
use Hub\Domain\Capability\Definition\ScaleCapabilityDefinitions;
use Hub\Domain\Capability\Definition\PulseOximeterCapabilityDefinitions;
use Hub\Domain\Capability\Definition\ThermometerCapabilityDefinitions;
use Hub\Domain\Capability\Definition\GatewayCapabilityDefinitions;
use Hub\Domain\Capability\Definition\BloodPressureMonitorCapabilityDefinitions;
use Hub\Domain\Capability\Definition\BraceletCapabilityDefinitions;
use Hub\Domain\Capability\Definition\DiaperSensorCapabilityDefinitions;
use Hub\Domain\Capability\Definition\PillDispenserCapabilityDefinitions;
use Hub\Domain\Capability\Definition\WatchCapabilityDefinitions;
use Hub\Domain\DeviceMetadata;
use Hub\Domain\DeviceTypeCatalog;
use Hub\Domain\ProtocolRegistry;

/**
 * O catálogo autoritativo da identidade de cada capacidade genérica e do suporte por protocolo.
 * As definições nativas vivem no `DeviceConfigurationCatalog`; só aqui se mapeiam no contrato.
 */
final class CapabilityCatalog
{
    /**
     * @return list<string>
     */
    public static function deviceTypes(): array
    {
        return DeviceTypeCatalog::keys();
    }

    /** @return list<string> */
    public static function sections(): array
    {
        return ['telemetry', 'health', 'contacts', 'alarms', 'reminders', 'settings_system'];
    }

    /**
     * @return list<array{deviceType: string, section: string, key: string, isTelemetry: bool, isConfigurable: bool, isRequestable: bool, isEvent?: bool}>
     */
    public static function definitions(): array
    {
        static $cache = null;

        return $cache ??= array_merge(
            WatchCapabilityDefinitions::all(),
            NcsCapabilityDefinitions::all(),
            RadarCapabilityDefinitions::all(),
            GatewayCapabilityDefinitions::all(),
            DiaperSensorCapabilityDefinitions::all(),
            BraceletCapabilityDefinitions::all(),
            PillDispenserCapabilityDefinitions::all(),
            ScaleCapabilityDefinitions::all(),
            BloodPressureMonitorCapabilityDefinitions::all(),
            ThermometerCapabilityDefinitions::all(),
            PulseOximeterCapabilityDefinitions::all(),
        );
    }

    /**
     * @return list<string>
     */
    public static function keysForDeviceType(string $deviceType): array
    {
        $keys = [];
        foreach (self::definitionsForDeviceType($deviceType) as $definition) {
            $keys[$definition['key']] = true;
        }

        return array_keys($keys);
    }

    /**
     * Se a capacidade é acontecimento -- canal `events`, QoS 1 -- ou leitura, `telemetry` a QoS 0.
     * Pela chave: um teste prende que a bandeira não discorda entre catálogos.
     */
    public static function isEventType(string $type): bool
    {
        static $events = null;
        if ($events === null) {
            $events = [];
            foreach (self::definitions() as $definition) {
                if (($definition['isEvent'] ?? false) === true) {
                    $events[(string)$definition['key']] = true;
                }
            }
        }

        return isset($events[$type]);
    }

    /**
     * @return list<array{deviceType: string, section: string, key: string, isTelemetry: bool, isConfigurable: bool, isRequestable: bool, isEvent?: bool}>
     */
    public static function definitionsForDeviceType(string $deviceType): array
    {
        $normalized = DeviceMetadata::normalizeDeviceType($deviceType);

        return array_values(array_filter(
            self::definitions(),
            static fn(array $definition): bool => ($definition['deviceType'] ?? 'watch') === $normalized
        ));
    }

    /**
     * @return list<string>
     */
    public static function keysForProtocol(string $protocol): array
    {
        $keys = [];
        foreach (self::protocolSpecificKeys($protocol) as $protocolKey) {
            $keys[$protocolKey] = true;
        }
        foreach (self::telemetryKeysForProtocol($protocol) as $telemetryKey) {
            $keys[$telemetryKey] = true;
        }

        foreach (DeviceCommandCatalog::featuresForProtocol($protocol) as $feature) {
            $generic = self::mapTelemetryFeature($feature);
            if ($generic !== null) {
                $keys[$generic] = true;
            }
        }

        foreach (DeviceConfigurationCatalog::configsForProtocol($protocol) as $config) {
            $generic = self::mapConfigurationKey((string)($config['key'] ?? ''));
            if ($generic !== null) {
                $keys[$generic] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * Os acontecimentos que um protocolo publica, no canal `events`.
     *
     * @return list<string>
     */
    public static function protocolSpecificKeys(string $protocol): array
    {
        return self::publishedKeys($protocol, events: true);
    }

    /**
     * O que um protocolo publica no canal `telemetry`.
     *
     * @return list<string>
     */
    public static function telemetryKeysForProtocol(string $protocol): array
    {
        return self::publishedKeys($protocol, events: false);
    }

    /**
     * As chaves que um protocolo publica, separadas pelo canal em que saem.
     *
     * @return list<string>
     */
    private static function publishedKeys(string $protocol, bool $events): array
    {
        static $cache = [];
        $cache[$protocol] ??= self::buildPublishedKeys($protocol);

        return $cache[$protocol][$events ? 'events' : 'telemetry'];
    }

    /**
     * Pela ordem em que o tipo de aparelho as declara, que é a ordem por que a dashboard as
     * mostra.
     *
     * @return array{telemetry: list<string>, events: list<string>}
     */
    private static function buildPublishedKeys(string $protocol): array
    {
        $publishers = self::publishers();
        $deviceType = ProtocolRegistry::describe($protocol)['deviceType'];

        $published = ['telemetry' => [], 'events' => []];
        foreach (self::definitionsForDeviceType($deviceType) as $definition) {
            $key = (string)$definition['key'];
            if (!in_array($protocol, $publishers[$key] ?? [], true)) {
                continue;
            }
            $published[($definition['isEvent'] ?? false) === true ? 'events' : 'telemetry'][] = $key;
        }

        return $published;
    }

    /**
     * Quem publica cada capacidade. As listas somam-se entre ficheiros: a `battery` é de
     * cinco tipos de aparelho, e o `help_call` do NCS, da pulseira e do dispensador.
     *
     * @return array<string, list<string>>
     */
    private static function publishers(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $cache = [];
        $files = [
            WatchCapabilityDefinitions::publishers(),
            NcsCapabilityDefinitions::publishers(),
            RadarCapabilityDefinitions::publishers(),
            GatewayCapabilityDefinitions::publishers(),
            DiaperSensorCapabilityDefinitions::publishers(),
            BraceletCapabilityDefinitions::publishers(),
            PillDispenserCapabilityDefinitions::publishers(),
            ScaleCapabilityDefinitions::publishers(),
            BloodPressureMonitorCapabilityDefinitions::publishers(),
            ThermometerCapabilityDefinitions::publishers(),
            PulseOximeterCapabilityDefinitions::publishers(),
        ];

        foreach ($files as $publishers) {
            foreach ($publishers as $key => $protocols) {
                $cache[$key] = array_values(array_unique(array_merge($cache[$key] ?? [], $protocols)));
            }
        }

        return $cache;
    }

    /**
     * @param list<array{section?: string, capability_key?: string, key?: string}> $catalogRows
     * @param list<string> $supportedKeys
     * @return array<string, array<string, bool>>
     */
    public static function buildCapabilityMatrix(array $catalogRows, array $supportedKeys): array
    {
        $supported = array_fill_keys(self::normalizeKeys($supportedKeys), true);
        $matrix = [];
        foreach (self::sections() as $section) {
            $matrix[$section] = [];
        }

        foreach ($catalogRows as $row) {
            $section = trim((string)($row['section'] ?? ''));
            $key = trim((string)($row['capability_key'] ?? $row['key'] ?? ''));
            if ($section === '' || $key === '' || !isset($matrix[$section])) {
                continue;
            }
            $matrix[$section][$key] = isset($supported[$key]);
        }

        return $matrix;
    }

    public static function normalizeStoredCapabilityKey(string $key): ?string
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }

        if (self::isCatalogKey($key)) {
            return $key;
        }

        return self::mapConfigurationKey($key);
    }

    public static function sectionForCapabilityKey(string $key): ?string
    {
        foreach (self::definitions() as $definition) {
            if ($definition['key'] === $key) {
                return $definition['section'];
            }
        }

        return null;
    }

    public static function mapTelemetryFeature(string $feature): ?string
    {
        $feature = trim($feature);

        return match ($feature) {
            'heart_rate',
            'blood_pressure',
            'blood_oxygen',
            'temperature',
            'breath_rate',
            'location',
            'sleep',
            'ecg',
            'hrv',
            'ppg',
            'rr_interval',
            'battery',
            'activity',
            'blood_sugar',
            'firmware_version',
            // Não traz valor próprio: o que a resposta enche são as leituras que já existem.
            // Está aqui porque é uma capacidade pedível como as outras.
            'device_status' => $feature,
            default => null,
        };
    }

    public static function mapConfigurationKey(string $key): ?string
    {
        $key = trim($key);

        return DeviceConfigurationCatalog::capabilityKeys()[$key]
            ?? (self::isCatalogKey($key) ? $key : null);
    }

    private static function isCatalogKey(string $key): bool
    {
        static $keys = null;
        $keys ??= array_fill_keys(array_column(self::definitions(), 'key'), true);

        return isset($keys[$key]);
    }

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    private static function normalizeKeys(array $keys): array
    {
        $normalized = [];
        foreach ($keys as $key) {
            $key = trim($key);
            if ($key !== '') {
                $normalized[$key] = true;
            }
        }

        return array_keys($normalized);
    }
}
