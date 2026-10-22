<?php

declare(strict_types=1);

namespace Hub\Ingress\Http\Qinglanst;

use Hub\Device\HubMqttBridge;
use Hub\Domain\DeviceMetadata;
use Hub\Infrastructure\Persistence\Repository\DeviceConfigurationLifecycleRepository;
use Hub\Infrastructure\Persistence\Repository\WhitelistRepository;
use Hub\State\DeviceStoreContract;
use Hub\Device\TelemetryEnvelope;

/**
 * O que uma leitura do radar (`deviceProp`) diz passa a ser o que o hub mostra: as configurações
 * ficam como aplicadas, e o sinal, a inclinação e o firmware saem como telemetria.
 */
final class RadarStateAbsorber
{
    private const PROTOCOL = 'qinglanst-radar';

    public function __construct(
        private readonly DeviceConfigurationLifecycleRepository $lifecycle,
        private readonly QinglanstCloudCommands $commands,
        private readonly WhitelistRepository $whitelist,
        private readonly HubMqttBridge $mqtt,
        private readonly ?DeviceStoreContract $store = null,
    ) {
    }

    /** @param array<string, mixed> $props o `data` do `deviceProp` */
    public function absorb(string $imei, array $props): void
    {
        $names = $this->areaNames($imei);
        foreach ($this->commands->configurations($props) as $key => $payload) {
            if ($key === 'radar_areas') {
                $payload['items'] = self::withNames($payload['items'], $names);
            }
            $this->lifecycle->recordRead($imei, $key, $payload, self::PROTOCOL);
        }

        $device = $this->whitelist->get($imei);
        if ($device === null) {
            return;
        }
        $deviceType = (string)$device['device_type'];
        $licenseId = DeviceMetadata::normalizeLicenseId($device['license_id'] ?? 0);
        $company = (string)($device['company'] ?? 'null');
        foreach ($this->commands->telemetry($props) as $type => $data) {
            $telemetry = TelemetryEnvelope::for($type, $imei, $device, self::PROTOCOL, 'deviceProp', $data);
            $this->mqtt->publishAndStore($this->store, 'telemetry', $imei, $telemetry, $deviceType, $licenseId, $company);
        }
    }

    /**
     * Os nomes que as áreas têm no hub, pelo número. A API não escreve nomes, e a cloud guarda os
     * seus; o que se deu no hub ganha.
     *
     * @return array<int, string>
     */
    public function areaNames(string $imei): array
    {
        $items = $this->lifecycle->effectivePayloads($imei, ['radar_areas'])['radar_areas']['items'] ?? [];
        $names = [];
        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item) && isset($item['key']) && trim((string)($item['name'] ?? '')) !== '') {
                $names[(int)$item['key']] = (string)$item['name'];
            }
        }

        return $names;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<int, string> $names
     * @return list<array<string, mixed>>
     */
    private static function withNames(array $items, array $names): array
    {
        return array_map(
            static fn (array $item): array => array_replace($item, ['name' => $names[(int)$item['key']] ?? $item['name']]),
            $items,
        );
    }
}
