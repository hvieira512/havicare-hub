<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Moko;

use Hub\Dashboard\DashboardStoreContract;
use Hub\Device\CommercialModelResolver;
use Hub\Device\HubMqttBridge;
use Hub\Domain\DeviceMetadata;
use Hub\Domain\DeviceProtocol;
use Hub\Ingress\Mqtt\Gateway\ObservationStateStore;
use Hub\Registry\Whitelist;

/**
 * Põe no fio o que um gateway ouviu de um aparelho retransmitido.
 *
 * Nada aqui decide: recebe o que os decoders já reconheceram e os normalizadores já traduziram,
 * e escolhe apenas o destino -- o MQTT, que leva tudo, e o histórico da dashboard, que leva
 * uma amostra. Essa distinção é o assunto desta classe, e é a razão de ela existir separada da
 * `Bridge`, que encaminha.
 */
final class RelayPublisher
{
    /** Como cada tipo retransmitido reporta, para quando não há observação de onde o ler. */
    private const RELAYED_PROTOCOLS = [
        'bracelet' => 'moko-w6b',
        'diaper_sensor' => 'monit-mecs-pro-ble',
    ];

    /** Quando o último `raw` de cada aparelho foi guardado no histórico. @var array<string, float> */
    private array $lastRelayedRawAt = [];

    private ?ProximityTracker $proximity = null;

    private \Closure $clock;

    public function __construct(
        private readonly HubMqttBridge $mqttBridge,
        private readonly ?DashboardStoreContract $dashboardStore,
        private readonly ObservationStateStore $state,
        private readonly Whitelist $whitelist,
        private readonly ?CommercialModelResolver $commercialModelResolver,
        private readonly int $telemetryRefreshSeconds,
        private readonly int $rawHistorySampleSeconds,
        private readonly ?ProximityTracker $proximityTracker = null,
        ?callable $clock = null,
    ) {
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn(): float => microtime(true);
    }

    /**
     * Guarda a observação crua no histórico do aparelho retransmitido, para debugging.
     *
     * No histórico **dele** e não do gateway de propósito: as observações são de alta
     * frequência e afogariam as tramas de estado do gateway; a lista `raw` do aparelho é
     * dedicada, portanto não expulsa a sua própria telemetria. Só para aparelhos já
     * autorizados -- o `$device` chega resolvido e ligado a este gateway.
     *
     * @param array<string, mixed> $device @param array<string, mixed> $gateway
     * @param array<string, mixed> $observation
     */
    public function recordRaw(array $device, array $gateway, string $protocol, array $observation): void
    {
        $deviceKey = (string)$device['imei'];
        $deviceType = (string)$device['deviceType'];
        $licenseId = DeviceMetadata::normalizeLicenseId($device['licenseId'] ?? 0);
        $company = (string)($device['company'] ?? 'null');
        $raw = [
            'direction' => 'uplink',
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'device' => self::describe($device),
            'data' => $observation,
            'debug' => [
                'protocol' => $protocol,
                'transport' => 'ble_gateway',
                'encoding' => 'json',
                'payload' => $observation,
                'gatewayId' => (string)$gateway['imei'],
            ],
        ];
        // O MQTT leva todas as observações -- é o debugging ao vivo; o histórico da dashboard
        // leva uma amostra por dispositivo, para não afogar a janela nem somar escritas.
        $this->mqttBridge->publishRaw($deviceKey, $raw, $deviceType, $licenseId, $company);
        if ($this->dashboardStore !== null && $this->shouldStoreRaw($deviceKey)) {
            $this->dashboardStore->append($deviceKey, 'raw', $raw + ['deviceType' => $deviceType, 'licenseId' => $licenseId]);
        }
    }

    /**
     * @param array<string, mixed> $device @param array<string, mixed> $gateway
     * @param array<string, mixed> $normalized
     */
    public function publishTelemetry(
        array $device,
        array $gateway,
        string $protocol,
        array $normalized,
        mixed $rssiDbm,
    ): void {
        $deviceKey = (string)$device['imei'];
        $deviceType = (string)$device['deviceType'];
        $licenseId = DeviceMetadata::normalizeLicenseId($device['licenseId'] ?? 0);
        $company = (string)($device['company'] ?? 'null');
        $this->dashboardStore?->deviceSeen($deviceKey, [
            'supplier' => (string)$device['supplier'], 'model' => (string)$device['model'],
            'deviceType' => $deviceType, 'licenseId' => $licenseId, 'company' => $company,
            'protocol' => $protocol, 'transport' => 'ble_gateway', 'online' => '1',
        ]);
        $this->recordSignal($device, $gateway, $protocol, $rssiDbm);

        foreach ($normalized['telemetry'] as $capability => $telemetry) {
            if (!$this->state->shouldPublish($deviceKey, (string)$capability, $telemetry, $this->telemetryRefreshSeconds, (string)$gateway['imei'])) {
                continue;
            }
            $this->mqttBridge->publishTelemetry($deviceKey, $telemetry, $deviceType, $licenseId, $company);
            $this->dashboardStore?->append($deviceKey, 'telemetry', $telemetry + ['deviceType' => $deviceType, 'licenseId' => $licenseId]);
        }
    }

    /**
     * Publica os eventos de um aparelho retransmitido no MQTT e no histórico dele.
     *
     * @param array<string, mixed> $device @param array<string, mixed> $gateway
     * @param list<array<string, mixed>> $events
     */
    public function publishEvents(array $device, array $gateway, array $events): void
    {
        $deviceKey = (string)$device['imei'];
        $deviceType = (string)$device['deviceType'];
        $licenseId = DeviceMetadata::normalizeLicenseId($device['licenseId'] ?? 0);
        $company = (string)($device['company'] ?? 'null');
        foreach ($events as $event) {
            $this->mqttBridge->publishEvent($deviceKey, $event, $deviceType, $licenseId, $company);
            $this->dashboardStore?->append($deviceKey, 'events', $event + ['deviceType' => $deviceType, 'licenseId' => $licenseId]);
        }
    }

    /**
     * Diz ao cliente quando um par se calou. `unknown` não é `far`: fora de alcance, bateria
     * descarregada e gateway offline são indistinguíveis. Reportado uma vez por par.
     */
    public function expireStaleProximity(): void
    {
        foreach ($this->proximity()->takeStale((float)($this->clock)()) as $pair) {
            $device = $this->whitelist->resolve($pair['deviceKey']);
            $gateway = $this->whitelist->resolve($pair['gatewayKey']);
            if ($device === null || $gateway === null) {
                continue;
            }
            try {
                $this->publishProximity(
                    $this->commercialModelResolver === null
                        ? $device
                        : $this->withCommercialName($device),
                    $gateway,
                    self::relayedProtocol($device),
                    ['state' => 'unknown', 'samples' => 0],
                );
            } catch (\Throwable $e) {
                $this->mqttBridge->logPublishFailure('hub', (string)$pair['deviceKey'], $e);
            }
        }
    }

    /** Se este tipo de aparelho é dos que um gateway retransmite. */
    public static function relays(string $deviceType): bool
    {
        return isset(self::RELAYED_PROTOCOLS[$deviceType]);
    }

    /**
     * O protocolo por que um aparelho retransmitido reporta.
     *
     * O tipo sozinho não chega: uma pulseira tanto é W6 como W6B, e nem sequer é
     * necessariamente MOKO -- um gateway MOKO vê tudo o que anuncia à sua volta, e a MF91 da
     * Wonlex fala Veepoo. Quem sabe isto é o `DeviceProtocol`, que resolve pelo par
     * fornecedor/modelo; o tipo fica como último recurso, para um modelo que ele não conheça.
     *
     * @param array<string, mixed> $device
     */
    public static function relayedProtocol(array $device): string
    {
        $protocol = DeviceProtocol::forModel(
            (string)($device['supplier'] ?? ''),
            (string)($device['model'] ?? ''),
        );

        return $protocol !== ''
            ? $protocol
            : (self::RELAYED_PROTOCOLS[(string)($device['deviceType'] ?? '')] ?? 'moko-gateway');
    }

    /**
     * O dispositivo como ele sai no envelope: só os campos que têm valor.
     *
     * @param array<string, mixed> $device
     * @return array<string, string>
     */
    public static function describe(array $device): array
    {
        return array_filter([
            'id' => (string)$device['imei'], 'supplier' => (string)($device['supplier'] ?? ''),
            'model' => (string)($device['model'] ?? ''), 'commercialName' => (string)($device['commercialName'] ?? ''),
        ], static fn(string $value): bool => $value !== '');
    }

    /**
     * O sinal entre um dispositivo retransmitido e o gateway que o ouviu.
     *
     * Publicado por avistamento, fora do `shouldPublish()`: esse compara os dados de
     * telemetria, e o sinal mexe-se quando as leituras não mexem. Não entra no histórico do
     * dispositivo, que a quarenta avistamentos por minuto ficaria só com isto.
     *
     * @param array<string, mixed> $device o dispositivo retransmitido, já autorizado
     * @param array<string, mixed> $gateway
     */
    public function recordSignal(array $device, array $gateway, string $protocol, mixed $rssiDbm): void
    {
        $deviceKey = (string)$device['imei'];
        $gatewayKey = (string)$gateway['imei'];
        $this->dashboardStore?->recordGatewaySighting(
            $deviceKey,
            $gatewayKey,
            is_numeric($rssiDbm) ? (int)$rssiDbm : null,
        );
        if (!is_numeric($rssiDbm)) {
            return;
        }

        $this->publishProximity(
            $device,
            $gateway,
            $protocol,
            $this->proximity()->record($deviceKey, $gatewayKey, (int)$rssiDbm, (float)($this->clock)()),
        );
    }

    /**
     * @param array<string, mixed> $device
     * @param array<string, mixed> $gateway
     * @param array<string, mixed> $data
     */
    private function publishProximity(array $device, array $gateway, string $protocol, array $data): void
    {
        $this->mqttBridge->publishTelemetry(
            (string)$device['imei'],
            [
                'type' => 'proximity',
                'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
                'device' => self::describe($device),
                'data' => ['gatewayId' => (string)$gateway['imei']] + $data,
                'source' => array_filter([
                    'protocol' => $protocol,
                    'nativeType' => 'manufacturer_data',
                    'gatewayId' => (string)$gateway['imei'],
                    'rssiDbm' => $data['rssiDbm'] ?? null,
                ], static fn(mixed $value): bool => $value !== null),
            ],
            (string)$device['deviceType'],
            DeviceMetadata::normalizeLicenseId($device['licenseId'] ?? 0),
            (string)($device['company'] ?? 'null'),
        );
    }

    private function shouldStoreRaw(string $deviceKey): bool
    {
        $now = (float)($this->clock)();
        if ($this->rawHistorySampleSeconds <= 0) {
            $this->lastRelayedRawAt[$deviceKey] = $now;
            return true;
        }

        $last = $this->lastRelayedRawAt[$deviceKey] ?? null;
        if ($last !== null && ($now - $last) < $this->rawHistorySampleSeconds) {
            return false;
        }

        $this->lastRelayedRawAt[$deviceKey] = $now;
        return true;
    }

    /** Guardado, ao contrário dos decoders: leva a janela de amostras. */
    private function proximity(): ProximityTracker
    {
        return $this->proximity ??= $this->proximityTracker ?? new ProximityTracker();
    }

    /**
     * @param array<string, mixed> $device
     * @return array<string, mixed>
     */
    private function withCommercialName(array $device): array
    {
        $name = $this->commercialModelResolver?->resolveCommercialName(
            (string)($device['supplier'] ?? ''),
            (string)($device['model'] ?? ''),
        ) ?? '';

        if ($name !== '') {
            $device['commercialName'] = $name;
        }

        return $device;
    }
}
