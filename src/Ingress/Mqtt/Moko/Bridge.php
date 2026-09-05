<?php

namespace Hub\Ingress\Mqtt\Moko;

use Hub\Domain\DeviceMetadata;
use Hub\Device\CommercialModelResolver;
use Hub\Domain\DiaperSensitivity;
use Hub\Domain\DiaperSensitivityLookup;
use Hub\Domain\GatewayDeviceLinkLookup;
use Hub\Log\Logger;
use Hub\Device\RawPayload;

final class Bridge extends \Hub\Ingress\Mqtt\Bridge
{
    /** Como cada tipo retransmitido reporta, para quando não há observação de onde o ler. */
    private const RELAYED_PROTOCOLS = [
        'bracelet' => 'moko-w6b',
        'diaper_sensor' => 'monit-mecs-pro-ble',
    ];

    /** Relatórios de scan, e não estado do gateway. 3070 é MKGW3; 30a0 e 30b2 são MKGW4. */
    private const SCAN_MESSAGE_IDS = ['3070', '30a0', '30b2'];
    // O modelo desempata as pulseiras: ver `relayedProtocol()`.

    /**
     * Um pouco mais do que os 30 segundos que o slot anuncia, para a frame repetida dar um
     * alarme e não trinta.
     *
     * ponytail: dois toques do mesmo modo na mesma janela contam como um -- a frame não traz
     * contador.
     */
    private const W6_PRESS_WINDOW_SECONDS = 35;

    /** @var array<string, array<string, mixed>> */
    private array $onlineGateways = [];
    /** @var array<string, float> */
    private array $gatewayLastSeenAt = [];
    /** @var array<string, float> */
    private array $lastRelayedRawAt = [];
    /** A manutenção corre no máximo uma vez a cada tantos segundos, e não a cada tique. */
    private const MAINTENANCE_INTERVAL_SECONDS = 5.0;
    private float $lastMaintenanceAt = 0.0;
    private \Closure $clock;

    public function __construct(
        \PhpMqtt\Client\MqttClient $subscriber,
        \Hub\Registry\Whitelist $whitelist,
        \Hub\Device\HubMqttBridge $mqttBridge,
        GatewayDeviceLinkLookup $links,
        ObservationStateStore $state,
        string $topicFilter = 'havicare-hub/null/0/gw/+/raw',
        ?callable $reconnectSubscriber = null,
        ?\Hub\Dashboard\DashboardStoreContract $dashboardStore = null,
        private readonly ?CommercialModelResolver $commercialModelResolver = null,
        private readonly int $dedupeTtlSeconds = 5,
        private readonly int $telemetryRefreshSeconds = 60,
        private readonly int $gatewayIdleTimeoutSeconds = 180,
        private readonly int $rawHistorySampleSeconds = 30,
        private readonly ?MessageDecoder $messageDecoder = null,
        private readonly ?MonitMecsProDecoder $monitDecoder = null,
        private readonly ?MonitNormalizer $monitNormalizer = null,
        private readonly ?GatewayNormalizer $gatewayNormalizer = null,
        private readonly ?W6bDecoder $w6bDecoder = null,
        private readonly ?W6bNormalizer $w6bNormalizer = null,
        private readonly ?W6Decoder $w6Decoder = null,
        private readonly ?W6Normalizer $w6Normalizer = null,
        ?callable $clock = null,
        private readonly ?ProximityTracker $proximityTracker = null,
        private readonly ?DiaperSensitivityLookup $diaperSensitivity = null,
    ) {
        parent::__construct(
            $subscriber,
            $whitelist,
            $mqttBridge,
            $topicFilter,
            sourceName: 'moko-gateway',
            reconnectSubscriber: $reconnectSubscriber,
            dashboardStore: $dashboardStore,
        );
        $this->links = $links;
        $this->state = $state;
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn(): float => microtime(true);
    }

    private readonly GatewayDeviceLinkLookup $links;
    private readonly ObservationStateStore $state;

    /** Guardado, ao contrário dos decoders acima: leva a janela de amostras. */
    private ?ProximityTracker $proximity = null;

    private function proximity(): ProximityTracker
    {
        return $this->proximity ??= $this->proximityTracker ?? new ProximityTracker();
    }

    public function tick(float $timeout = 0.01): void
    {
        parent::tick($timeout);
        $this->runDueMaintenance();
    }

    /**
     * Expira gateways parados e pares silenciosos, no máximo uma vez por janela.
     *
     * Impõe limiares de 180 e 30 segundos, e não tem nada que correr a cada tique de 50 ms --
     * o `expireStaleProximity` varre todos os pares, e a 20 vezes por segundo era desperdício.
     * O `loopOnce` continua a correr a cada tique, que é onde o MQTT é drenado; só isto sai
     * para uma janela. Público para os testes o exercerem sem o `loopOnce` do tique.
     */
    public function runDueMaintenance(): void
    {
        $now = (float)($this->clock)();
        if ($now - $this->lastMaintenanceAt < self::MAINTENANCE_INTERVAL_SECONDS) {
            return;
        }
        $this->lastMaintenanceAt = $now;
        $this->expireIdleGateways();
        $this->expireStaleProximity();
    }

    public function expireIdleGateways(): void
    {
        $now = ($this->clock)();
        foreach ($this->onlineGateways as $deviceKey => $gateway) {
            if ($now - ($this->gatewayLastSeenAt[$deviceKey] ?? $now) < $this->gatewayIdleTimeoutSeconds) {
                continue;
            }
            $deviceType = (string)$gateway['deviceType'];
            $licenseId = DeviceMetadata::normalizeLicenseId($gateway['licenseId'] ?? 0);
            $company = (string)($gateway['company'] ?? 'null');
            $status = RawPayload::status($deviceKey, (string)$gateway['supplier'], (string)$gateway['model'], 'offline', null, (string)($gateway['commercialName'] ?? ''));
            $event = RawPayload::event($deviceKey, (string)$gateway['supplier'], (string)$gateway['model'], 'device.disconnected', null, null, (string)($gateway['commercialName'] ?? ''));
            $this->mqttBridge->publishStatus($deviceKey, $status, true, $deviceType, $licenseId, $company);
            $this->mqttBridge->publishEvent($deviceKey, $event, $deviceType, $licenseId, $company);
            $this->dashboardStore?->deviceOffline($deviceKey);
            $this->dashboardStore?->append($deviceKey, 'events', $event + ['deviceType' => $deviceType, 'licenseId' => $licenseId]);
            unset($this->onlineGateways[$deviceKey], $this->gatewayLastSeenAt[$deviceKey]);
        }
    }

    protected function handleMessage(string $topic, string $payload): void
    {
        $parsedTopic = Topic::parse($topic);
        if ($parsedTopic === null) {
            Logger::channel('hub')->warning("Ignoring unsupported MOKO gateway topic {$topic}");
            return;
        }

        $decoded = ($this->messageDecoder ?? new MokoMessageDecoder())->decode($payload);

        $gateway = $this->whitelist->resolve($parsedTopic->gatewayMac);
        if ($gateway === null || ($gateway['deviceType'] ?? '') !== 'gateway') {
            // O assistente de registo tira do protocolo o fornecedor, o tipo e os modelos
            // possíveis, e o modelo exacto não se deduz: no fio um MKGW3 e um MKGW-mini
            // são iguais.
            $this->recordUnauthorizedDevice(
                $parsedTopic->gatewayMac,
                (string)($decoded['protocol'] ?? ''),
                ident: $parsedTopic->gatewayMac
            );
            Logger::channel('hub')->warning("Ignoring unregistered MOKO gateway mac={$parsedTopic->gatewayMac}");
            return;
        }

        if ($decoded === null || $decoded['gatewayMac'] !== $parsedTopic->gatewayMac) {
            Logger::channel('hub')->warning("Ignoring invalid MOKO gateway payload or gateway MAC mismatch on {$topic}");
            return;
        }
        $gateway = $this->enrich($gateway);
        $this->recordGateway($gateway, $decoded, $topic, $payload);

        if (in_array((string)$decoded['messageId'], self::SCAN_MESSAGE_IDS, true) && is_array($decoded['data'])) {
            foreach ($decoded['data'] as $observation) {
                if (is_array($observation)) {
                    $this->handleObservation($gateway, $observation);
                }
            }
        }
    }

    /** @param array<string, mixed> $gateway @param array<string, mixed> $decoded */
    private function recordGateway(array $gateway, array $decoded, string $sourceTopic, string $originalPayload): void
    {
        $deviceKey = (string)$gateway['imei'];
        $deviceType = (string)$gateway['deviceType'];
        $licenseId = DeviceMetadata::normalizeLicenseId($gateway['licenseId'] ?? 0);
        $company = (string)($gateway['company'] ?? 'null');
        $this->gatewayLastSeenAt[$deviceKey] = ($this->clock)();
        $protocol = (string)($decoded['protocol'] ?? 'moko-gateway');
        $encoding = (string)($decoded['encoding'] ?? 'unknown');
        $raw = [
            'direction' => 'uplink',
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'device' => $this->device($gateway),
            'data' => $decoded,
            'debug' => [
                'protocol' => $protocol,
                'transport' => 'mqtt',
                'encoding' => $encoding,
                'payload' => $encoding === 'json' ? json_decode($originalPayload, true) : bin2hex($originalPayload),
                'sourceTopic' => $sourceTopic,
            ],
        ];
        $this->mqttBridge->publishRaw($deviceKey, $raw, $deviceType, $licenseId, $company);
        $this->dashboardStore?->deviceSeen($deviceKey, [
            'supplier' => (string)$gateway['supplier'], 'model' => (string)$gateway['model'],
            'deviceType' => $deviceType, 'licenseId' => $licenseId, 'company' => $company,
            'protocol' => $protocol, 'transport' => 'mqtt', 'online' => '1',
        ]);
        // Só as tramas do próprio gateway entram no histórico dele; os scans descrevem os
        // dispositivos retransmitidos, que já têm o seu. No MQTT continua a sair tudo.
        if (!in_array((string)$decoded['messageId'], self::SCAN_MESSAGE_IDS, true)) {
            $this->dashboardStore?->append($deviceKey, 'raw', $raw + ['deviceType' => $deviceType, 'licenseId' => $licenseId]);
        }

        foreach (($this->gatewayNormalizer ?? new GatewayNormalizer())->telemetry($decoded, $gateway) as $telemetry) {
            if (!$this->state->shouldPublish($deviceKey, (string)$telemetry['type'], $telemetry, $this->telemetryRefreshSeconds)) {
                continue;
            }
            $this->mqttBridge->publishTelemetry($deviceKey, $telemetry, $deviceType, $licenseId, $company);
            $this->dashboardStore?->append($deviceKey, 'telemetry', $telemetry + ['deviceType' => $deviceType, 'licenseId' => $licenseId]);
        }

        if (!isset($this->onlineGateways[$deviceKey])) {
            $this->onlineGateways[$deviceKey] = $gateway;
            $status = RawPayload::status($deviceKey, (string)$gateway['supplier'], (string)$gateway['model'], 'online', null, (string)($gateway['commercialName'] ?? ''));
            $event = RawPayload::event($deviceKey, (string)$gateway['supplier'], (string)$gateway['model'], 'device.connected', null, null, (string)($gateway['commercialName'] ?? ''));
            $this->mqttBridge->publishStatus($deviceKey, $status, true, $deviceType, $licenseId, $company);
            $this->mqttBridge->publishEvent($deviceKey, $event, $deviceType, $licenseId, $company);
            $this->dashboardStore?->append($deviceKey, 'events', $event + ['deviceType' => $deviceType, 'licenseId' => $licenseId]);
        }
    }

    /**
     * Cada observação é oferecida aos decoders por ordem; o primeiro que a reconhece fica
     * com o payload.
     *
     * @param array<string, mixed> $gateway @param array<string, mixed> $observation
     */
    private function handleObservation(array $gateway, array $observation): void
    {
        $monit = ($this->monitDecoder ?? new MonitMecsProDecoder())->decode($observation);
        if ($monit !== null) {
            $this->handleMonitObservation($gateway, $monit, $observation);
            return;
        }

        $w6b = ($this->w6bDecoder ?? new W6bDecoder())->decode($observation);
        if ($w6b !== null) {
            $this->handleW6bObservation($gateway, $w6b, $observation);
            return;
        }

        $w6 = ($this->w6Decoder ?? new W6Decoder())->decode($observation);
        if ($w6 !== null) {
            $this->handleW6Observation($gateway, $w6, $observation);
            return;
        }

        $this->recordUnclaimedSighting($gateway, $observation);
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
    private function recordRelayedRaw(array $device, array $gateway, string $protocol, array $observation): void
    {
        $deviceKey = (string)$device['imei'];
        $deviceType = (string)$device['deviceType'];
        $licenseId = DeviceMetadata::normalizeLicenseId($device['licenseId'] ?? 0);
        $company = (string)($device['company'] ?? 'null');
        $raw = [
            'direction' => 'uplink',
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'device' => $this->device($device),
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
        if ($this->dashboardStore !== null && $this->shouldStoreRelayedRaw($deviceKey)) {
            $this->dashboardStore->append($deviceKey, 'raw', $raw + ['deviceType' => $deviceType, 'licenseId' => $licenseId]);
        }
    }

    private function shouldStoreRelayedRaw(string $deviceKey): bool
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

    /**
     * O sinal de um avistamento que nenhum decoder reclamou. O RSSI é medido pelo gateway e
     * existe quer se saiba ler o anúncio, quer não -- descartá-lo perdia amostras.
     *
     * Só para dispositivos já registados e ligados a este gateway.
     *
     * @param array<string, mixed> $gateway @param array<string, mixed> $observation
     */
    private function recordUnclaimedSighting(array $gateway, array $observation): void
    {
        $mac = Topic::normalizeMac((string)($observation['mac'] ?? ''));
        if ($mac === null || !is_numeric($observation['rssi'] ?? null)) {
            return;
        }

        $known = $this->whitelist->resolve($mac);
        $deviceType = (string)($known['deviceType'] ?? '');
        if ($known === null || !isset(self::RELAYED_PROTOCOLS[$deviceType])) {
            return;
        }

        $protocol = $this->relayedProtocol($known);
        $device = $this->linkedDevice($gateway, $mac, $deviceType, $protocol);
        if ($device === null) {
            return;
        }

        // Um aparelho registado cujo anúncio nenhum decoder leu; o raw ajuda a perceber porquê.
        $this->recordRelayedRaw($device, $gateway, $protocol, $observation);
        $this->recordSignal($device, $gateway, $protocol, $observation['rssi']);
    }

    /**
     * O tipo sozinho não chega: uma pulseira tanto é W6 como W6B, e é o modelo que as separa.
     *
     * @param array<string, mixed> $device
     */
    private function relayedProtocol(array $device): string
    {
        if (strtoupper((string)($device['model'] ?? '')) === 'W6') {
            return 'moko-w6';
        }

        return self::RELAYED_PROTOCOLS[(string)($device['deviceType'] ?? '')] ?? 'moko-gateway';
    }

    /**
     * Resolve um dispositivo retransmitido e confirma que pode chegar-nos por este gateway.
     *
     * @param array<string, mixed> $gateway
     * @return array<string, mixed>|null
     */
    private function linkedDevice(
        array $gateway,
        string $deviceKey,
        string $deviceType,
        string $protocol,
        string $model = '',
    ): ?array {
        $device = $this->whitelist->resolve($deviceKey);
        if ($device === null || ($device['deviceType'] ?? '') !== $deviceType) {
            $this->recordUnauthorizedDevice($deviceKey, $protocol, $model, ident: $deviceKey);
            return null;
        }

        if (
            !$this->links->isEnabled((string)$gateway['imei'], (string)$device['imei'])
            || (string)($gateway['company'] ?? 'null') !== (string)($device['company'] ?? 'null')
            || (string)$gateway['licenseId'] !== (string)$device['licenseId']
        ) {
            Logger::channel('hub')->warning(
                "Ignoring unlinked {$protocol} device={$deviceKey} gateway={$gateway['imei']}"
            );
            return null;
        }

        return $this->enrich($device);
    }

    /**
     * Uma W6B premida anuncia 30 segundos: cada modo tem contador, e só uma mudança é toque.
     *
     * @param array<string, mixed> $gateway @param array<string, mixed> $decoded
     */
    private function handleW6bObservation(array $gateway, array $decoded, array $observation): void
    {
        $deviceKey = (string)$decoded['mac'];
        $device = $this->linkedDevice($gateway, $deviceKey, 'bracelet', 'moko-w6b');
        if ($device === null) {
            return;
        }
        $this->recordRelayedRaw($device, $gateway, 'moko-w6b', $observation);

        $previousTriggerCount = null;
        if (isset($decoded['alarm']['pressMode'], $decoded['alarm']['triggerCount'])) {
            $transition = $this->state->transitionCondition(
                $deviceKey . ':press:' . $decoded['alarm']['pressMode'],
                (string)$decoded['alarm']['triggerCount'],
            );
            // O contador é cumulativo: vê-lo pela primeira vez não é um toque. É o contrário
            // da fralda abaixo, onde a primeira observação já suja tem de dar alarme.
            $previousTriggerCount = $transition === null || $transition['previous'] === null
                ? null
                : (int)$transition['previous'];
        }

        $normalized = ($this->w6bNormalizer ?? new W6bNormalizer())->normalize(
            $decoded,
            $device,
            (string)$gateway['imei'],
            $previousTriggerCount,
        );

        $deviceType = (string)$device['deviceType'];
        $licenseId = DeviceMetadata::normalizeLicenseId($device['licenseId'] ?? 0);
        $company = (string)($device['company'] ?? 'null');
        $this->dashboardStore?->deviceSeen($deviceKey, [
            'supplier' => (string)$device['supplier'], 'model' => (string)$device['model'],
            'deviceType' => $deviceType, 'licenseId' => $licenseId, 'company' => $company,
            'protocol' => 'moko-w6b', 'transport' => 'ble_gateway', 'online' => '1',
        ]);
        $this->recordSignal($device, $gateway, 'moko-w6b', $decoded['rssiDbm'] ?? null);

        foreach ($normalized['telemetry'] as $capability => $telemetry) {
            if (!$this->state->shouldPublish($deviceKey, $capability, $telemetry, $this->telemetryRefreshSeconds, (string)$gateway['imei'])) {
                continue;
            }
            $this->mqttBridge->publishTelemetry($deviceKey, $telemetry, $deviceType, $licenseId, $company);
            $this->dashboardStore?->append($deviceKey, 'telemetry', $telemetry + ['deviceType' => $deviceType, 'licenseId' => $licenseId]);
        }

        foreach ($normalized['events'] as $event) {
            $this->mqttBridge->publishEvent($deviceKey, $event, $deviceType, $licenseId, $company);
            $this->dashboardStore?->append($deviceKey, 'events', $event + ['deviceType' => $deviceType, 'licenseId' => $licenseId]);
        }
    }

    /**
     * A W6 não tem contador cumulativo, e por isso o toque é estrangulado por tempo: o
     * primeiro avistamento de um modo dá o alarme, os seguintes calam-se até a janela fechar.
     *
     * @param array<string, mixed> $gateway @param array<string, mixed> $decoded
     * @param array<string, mixed> $observation
     */
    private function handleW6Observation(array $gateway, array $decoded, array $observation): void
    {
        $deviceKey = (string)$decoded['mac'];
        $device = $this->linkedDevice($gateway, $deviceKey, 'bracelet', 'moko-w6', 'W6');
        if ($device === null) {
            return;
        }
        $this->recordRelayedRaw($device, $gateway, 'moko-w6', $observation);

        $pressMode = (string)($decoded['alarm']['pressMode'] ?? '');
        if (
            $pressMode !== '' && !$this->state->acceptObservation(
                $deviceKey . ':press:' . $pressMode,
                'w6-press',
                self::W6_PRESS_WINDOW_SECONDS,
            )
        ) {
            unset($decoded['alarm']);
        }

        $normalized = ($this->w6Normalizer ?? new W6Normalizer())
            ->normalize($decoded, $device, (string)$gateway['imei']);

        $deviceType = (string)$device['deviceType'];
        $licenseId = DeviceMetadata::normalizeLicenseId($device['licenseId'] ?? 0);
        $company = (string)($device['company'] ?? 'null');
        $this->dashboardStore?->deviceSeen($deviceKey, [
            'supplier' => (string)$device['supplier'], 'model' => (string)$device['model'],
            'deviceType' => $deviceType, 'licenseId' => $licenseId, 'company' => $company,
            'protocol' => 'moko-w6', 'transport' => 'ble_gateway', 'online' => '1',
        ]);
        $this->recordSignal($device, $gateway, 'moko-w6', $decoded['rssiDbm'] ?? null);

        foreach ($normalized['telemetry'] as $capability => $telemetry) {
            if (!$this->state->shouldPublish($deviceKey, $capability, $telemetry, $this->telemetryRefreshSeconds, (string)$gateway['imei'])) {
                continue;
            }
            $this->mqttBridge->publishTelemetry($deviceKey, $telemetry, $deviceType, $licenseId, $company);
            $this->dashboardStore?->append($deviceKey, 'telemetry', $telemetry + ['deviceType' => $deviceType, 'licenseId' => $licenseId]);
        }

        foreach ($normalized['events'] as $event) {
            $this->mqttBridge->publishEvent($deviceKey, $event, $deviceType, $licenseId, $company);
            $this->dashboardStore?->append($deviceKey, 'events', $event + ['deviceType' => $deviceType, 'licenseId' => $licenseId]);
        }
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
    private function recordSignal(array $device, array $gateway, string $protocol, mixed $rssiDbm): void
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
            $this->proximity()->record($deviceKey, $gatewayKey, (int)$rssiDbm, ($this->clock)()),
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
                'device' => $this->device($device),
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

    /**
     * Diz ao cliente quando um par se calou. `unknown` não é `far`: fora de alcance, bateria
     * descarregada e gateway offline são indistinguíveis. Reportado uma vez por par.
     */
    public function expireStaleProximity(): void
    {
        foreach ($this->proximity()->takeStale(($this->clock)()) as $pair) {
            $device = $this->whitelist->resolve($pair['deviceKey']);
            $gateway = $this->whitelist->resolve($pair['gatewayKey']);
            if ($device === null || $gateway === null) {
                continue;
            }
            $this->publishProximity(
                $this->enrich($device),
                $gateway,
                $this->relayedProtocol($device),
                ['state' => 'unknown', 'samples' => 0],
            );
        }
    }

    /**
     * @param array<string, mixed> $gateway @param array<string, mixed> $decoded
     * @param array<string, mixed> $observation
     */
    private function handleMonitObservation(array $gateway, array $decoded, array $observation): void
    {
        $sensorKey = (string)$decoded['mac'];
        $sensor = $this->linkedDevice($gateway, $sensorKey, 'diaper_sensor', 'monit-mecs-pro-ble');
        if ($sensor === null) {
            return;
        }
        $this->recordRelayedRaw($sensor, $gateway, 'monit-mecs-pro-ble', $observation);
        if (!$this->state->acceptObservation($sensorKey, hash('sha256', (string)$decoded['raw20']), $this->dedupeTtlSeconds)) {
            return;
        }

        // Sem lookup ligado, a sensibilidade é a do preset normal. O valor por omissão está
        // aqui e não no normalizador, onde um parâmetro opcional esconderia uma ligação
        // esquecida.
        $sensitivity = $this->diaperSensitivity?->forDevice($sensorKey) ?? DiaperSensitivity::normal();
        $normalized = ($this->monitNormalizer ?? new MonitNormalizer())
            ->normalize($decoded, $sensor, (string)$gateway['imei'], $sensitivity);
        $deviceType = (string)$sensor['deviceType'];
        $licenseId = DeviceMetadata::normalizeLicenseId($sensor['licenseId'] ?? 0);
        $company = (string)($sensor['company'] ?? 'null');
        $this->dashboardStore?->deviceSeen($sensorKey, [
            'supplier' => (string)$sensor['supplier'], 'model' => (string)$sensor['model'],
            'deviceType' => $deviceType, 'licenseId' => $licenseId, 'company' => $company,
            'protocol' => 'monit-mecs-pro-ble', 'transport' => 'ble_gateway', 'online' => '1',
        ]);
        $this->recordSignal($sensor, $gateway, 'monit-mecs-pro-ble', $decoded['rssiDbm'] ?? null);
        foreach ($normalized['telemetry'] as $capability => $telemetry) {
            if (!$this->state->shouldPublish($sensorKey, $capability, $telemetry, $this->telemetryRefreshSeconds, (string)$gateway['imei'])) {
                continue;
            }
            $this->mqttBridge->publishTelemetry($sensorKey, $telemetry, $deviceType, $licenseId, $company);
            $this->dashboardStore?->append($sensorKey, 'telemetry', $telemetry + ['deviceType' => $deviceType, 'licenseId' => $licenseId]);
        }

        // A sensibilidade entra no valor guardado e não na chave: apertá-la numa fralda já
        // suja tem de contar como transição e dar alarme.
        $transition = $this->state->transitionCondition(
            $sensorKey,
            $normalized['condition'] . '@' . $sensitivity['pollutionRange'] . '-' . $sensitivity['pollutionValue'],
        );
        if ($normalized['condition'] === 'change_required' && $transition !== null) {
            // A sensibilidade fica dentro do estado guardado e NÃO sai no evento: o
            // `previousState` é parte do contrato publicado e continua a ser um dos três
            // estados, ou nulo.
            $stored = $transition['previous'];
            $previous = is_string($stored) ? explode('@', $stored, 2)[0] : null;
            $event = [
                'type' => 'change_required', 'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
                'device' => $this->device($sensor), 'data' => ['previousState' => $previous],
                'source' => ['protocol' => 'monit-mecs-pro-ble', 'gatewayId' => (string)$gateway['imei']],
            ];
            $this->mqttBridge->publishEvent($sensorKey, $event, $deviceType, $licenseId, $company);
            $this->dashboardStore?->append($sensorKey, 'events', $event + ['deviceType' => $deviceType, 'licenseId' => $licenseId]);
        }
    }

    /** @param array<string, mixed> $device @return array<string, mixed> */
    private function enrich(array $device): array
    {
        if (($device['commercialName'] ?? '') === '') {
            $device['commercialName'] = $this->commercialModelResolver?->resolveCommercialName((string)$device['supplier'], (string)$device['model']) ?? '';
        }
        return $device;
    }

    /** @param array<string, mixed> $device */
    private function device(array $device): array
    {
        return array_filter([
            'id' => (string)$device['imei'], 'supplier' => (string)($device['supplier'] ?? ''),
            'model' => (string)($device['model'] ?? ''), 'commercialName' => (string)($device['commercialName'] ?? ''),
        ], static fn(string $value): bool => $value !== '');
    }
}
