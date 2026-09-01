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
    /**
     * Como cada tipo de dispositivo retransmitido reporta. Necessário quando o sinal se cala,
     * porque não sobra observação nenhuma de onde o ler.
     */
    private const RELAYED_PROTOCOLS = [
        'bracelet' => 'moko-w6b',
        'diaper_sensor' => 'monit-mecs-pro-ble',
    ];

    /**
     * As mensagens que trazem relatórios de scan, e não estado do próprio gateway.
     *
     * O 3070 é do MKGW3 (JSON); o 30a0 e o 30b2 são do MKGW4 (binário).
     */
    private const SCAN_MESSAGE_IDS = ['3070', '30a0', '30b2'];
    // O modelo desempata as pulseiras: ver `relayedProtocol()`.

    /**
     * Quanto tempo um toque da W6 cala os que vierem depois. Um pouco mais do que os 30
     * segundos que o slot anuncia, para a frame repetida dar um alarme e não trinta.
     *
     * ponytail: dois toques do mesmo modo dentro da mesma janela contam como um. A frame não
     * traz contador, e por isso não há como distingui-los.
     */
    private const W6_PRESS_WINDOW_SECONDS = 35;

    /** @var array<string, array<string, mixed>> */
    private array $onlineGateways = [];
    /** @var array<string, float> */
    private array $gatewayLastSeenAt = [];
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

    /**
     * Resolvido uma vez e guardado, ao contrário dos decoders sem estado acima: este leva a
     * janela de amostras, e uma instância nova por avistamento via-a sempre vazia.
     */
    private ?ProximityTracker $proximity = null;

    private function proximity(): ProximityTracker
    {
        return $this->proximity ??= $this->proximityTracker ?? new ProximityTracker();
    }

    public function tick(float $timeout = 0.01): void
    {
        parent::tick($timeout);
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

        $gateway = $this->whitelist->resolve($parsedTopic->gatewayMac);
        if ($gateway === null || ($gateway['deviceType'] ?? '') !== 'gateway') {
            $this->recordUnauthorizedDevice($parsedTopic->gatewayMac, 'moko-gateway', ident: $parsedTopic->gatewayMac);
            Logger::channel('hub')->warning("Ignoring unregistered MOKO gateway mac={$parsedTopic->gatewayMac}");
            return;
        }

        $decoded = ($this->messageDecoder ?? new MokoMessageDecoder())->decode($payload);
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
        // O histórico do gateway guarda as tramas do próprio gateway. Um relatório de scan
        // descreve os dispositivos retransmitidos, e cada um desses já tem o seu histórico.
        //
        // Publicar continua a publicar tudo: quem integra pelo MQTT lê a série completa. O que
        // não cabe é na lista da dashboard, que guarda 100 entradas -- em modo de reporte
        // imediato chegam duas mensagens por segundo, e a janela caía para menos de um minuto.
        // As tramas de estado, que trazem bateria e cobertura, eram despejadas em segundos e
        // deixavam de aparecer de todo.
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
     * Um gateway retransmite todos os dispositivos BLE que vê, por isso cada observação é
     * oferecida aos decoders por ordem e o primeiro que a reconhece fica com o payload.
     *
     * @param array<string, mixed> $gateway @param array<string, mixed> $observation
     */
    private function handleObservation(array $gateway, array $observation): void
    {
        $monit = ($this->monitDecoder ?? new MonitMecsProDecoder())->decode($observation);
        if ($monit !== null) {
            $this->handleMonitObservation($gateway, $monit);
            return;
        }

        $w6b = ($this->w6bDecoder ?? new W6bDecoder())->decode($observation);
        if ($w6b !== null) {
            $this->handleW6bObservation($gateway, $w6b);
            return;
        }

        $w6 = ($this->w6Decoder ?? new W6Decoder())->decode($observation);
        if ($w6 !== null) {
            $this->handleW6Observation($gateway, $w6);
            return;
        }

        $this->recordUnclaimedSighting($gateway, $observation);
    }

    /**
     * O sinal de um avistamento que nenhum decoder reclamou.
     *
     * O RSSI é medido pelo gateway e não vem no payload: existe na observação quer se saiba
     * ler o que o dispositivo anunciou, quer não. Enquanto um avistamento só contou depois de
     * um decoder o reclamar, cada frame que não sabíamos ler era uma amostra de proximidade
     * deitada fora.
     *
     * A W6 é o caso que o mostra. Anuncia em seis slots e nós lemos dois -- o acelerómetro e
     * os UID com o nosso namespace --, por isso o TLM e os UID de outra configuração caíam
     * todos. Medido no gateway F1F7: a W6 aparecia 59 vezes em 60 segundos e rendia 10
     * mensagens de proximidade, enquanto a W6B rendia 42 a partir de menos avistamentos.
     *
     * Só para dispositivos retransmitidos já registados e ligados a este gateway: um beacon
     * qualquer que passe continua a não ser assunto nosso.
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

        $this->recordSignal($device, $gateway, $protocol, $observation['rssi']);
    }

    /**
     * Como um dispositivo retransmitido reporta, quando não há observação de onde o ler.
     *
     * O tipo sozinho não chega: uma pulseira tanto é uma W6 como uma W6B, e o modelo é o que
     * as separa.
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
     * Uma W6B premida anuncia durante 30 segundos, e por isso a mesma contagem de toques
     * chega muitas vezes. Cada modo tem o seu contador, e só uma mudança é um toque novo.
     *
     * @param array<string, mixed> $gateway @param array<string, mixed> $decoded
     */
    private function handleW6bObservation(array $gateway, array $decoded): void
    {
        $deviceKey = (string)$decoded['mac'];
        $device = $this->linkedDevice($gateway, $deviceKey, 'bracelet', 'moko-w6b');
        if ($device === null) {
            return;
        }

        $previousTriggerCount = null;
        if (isset($decoded['alarm']['pressMode'], $decoded['alarm']['triggerCount'])) {
            $transition = $this->state->transitionCondition(
                $deviceKey . ':press:' . $decoded['alarm']['pressMode'],
                (string)$decoded['alarm']['triggerCount'],
            );
            // Não há toque nem quando o contador não se moveu nem no primeiro avistamento
            // desta pulseira: o contador é cumulativo, e vê-lo pela primeira vez não diz
            // nada sobre um toque ter acabado de acontecer. É o contrário da condição da
            // fralda abaixo, onde uma primeira observação já em `change_required` TEM de dar
            // alarme -- e é por isso que o store reporta os dois factos e cada chamador
            // decide o que fazer com eles.
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
     * Uma W6 premida põe o slot daquele modo a anunciar durante 30 segundos, e todos os
     * gateways em alcance repetem a frame. Como não há contador cumulativo por onde ver o
     * que é novo, o toque é estrangulado por tempo: o primeiro avistamento de um modo dá o
     * alarme e os seguintes calam-se até a janela fechar.
     *
     * @param array<string, mixed> $gateway @param array<string, mixed> $decoded
     */
    private function handleW6Observation(array $gateway, array $decoded): void
    {
        $deviceKey = (string)$decoded['mac'];
        $device = $this->linkedDevice($gateway, $deviceKey, 'bracelet', 'moko-w6', 'W6');
        if ($device === null) {
            return;
        }

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
     * Reporta o sinal entre um dispositivo retransmitido e o gateway que o ouviu.
     *
     * Publicado por avistamento e não pelo `shouldPublish()`: esse throttle faz impressão
     * digital dos dados de telemetria, e o sinal vive fora dela -- um avistamento cujo sinal
     * mexeu mas cujas leituras não mexeram era descartado, e o cliente ficava com uma série
     * com buracos que não podia ver.
     *
     * Também não vai para o histórico do dispositivo: a uns quarenta avistamentos por minuto
     * e por par, soterrava a lista e a tabela de telemetria da dashboard.
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
     * Diz ao cliente quando um par se calou.
     *
     * `unknown` não é `far`: fora de alcance, uma bateria descarregada, um gateway offline e
     * um filtro apertado demais são indistinguíveis entre si e de não estar ninguém lá.
     * Reportado uma vez por par, para o silêncio ficar silencioso depois disso.
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

    /** @param array<string, mixed> $gateway @param array<string, mixed> $decoded */
    private function handleMonitObservation(array $gateway, array $decoded): void
    {
        $sensorKey = (string)$decoded['mac'];
        $sensor = $this->linkedDevice($gateway, $sensorKey, 'diaper_sensor', 'monit-mecs-pro-ble');
        if ($sensor === null) {
            return;
        }
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

        // A sensibilidade entra no VALOR guardado e não na chave. Mudar a configuração muda a
        // condição derivada para a mesma leitura física, e essa mudança tem de contar como
        // transição -- senão um cuidador que aperta a sensibilidade numa fralda já suja não
        // recebe alarme nenhum.
        //
        // Na chave não servia: passar de `normal` para `low` e voltar a `normal` reencontrava
        // a chave antiga com `change_required` lá dentro, não via transição, e engolia o
        // alarme.
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
