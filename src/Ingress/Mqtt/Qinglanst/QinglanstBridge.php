<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Qinglanst;

use Hub\Device\ConnectionAnnouncer;
use Hub\Device\DeviceDescriptor;
use Hub\Domain\DeviceMetadata;
use Hub\Ingress\Mqtt\MqttBridgeBase;
use Hub\Log\Logger;

final class QinglanstBridge extends MqttBridgeBase
{
    private readonly PayloadDecoder $decoder;
    private readonly MessageNormalizer $normalizer;
    private readonly IngestStats $stats;
    private readonly DashboardWritePolicy $dashboardWritePolicy;
    private readonly ConnectionAnnouncer $connections;
    private readonly ActiveDetections $activeDetections;
    /** O varrimento dos radares calados corre no máximo de dez em dez segundos. */
    private const MAINTENANCE_INTERVAL_SECONDS = 10.0;
    private float $lastMaintenanceAt = 0.0;
    private const SUPPORTED_TYPES = ['position', 'heartbreath', 'posstatics', 'hbstatics'];
    /** O tópico traz a licença e não a empresa, e estes radares só existem para a hitcare. */
    private const RADAR_COMPANY = 'hitcare';

    public function __construct(
        \PhpMqtt\Client\MqttClient $subscriber,
        \Hub\Registry\Whitelist $whitelist,
        \Hub\Device\HubMqttBridge $mqttBridge,
        string $topicFilter = 'radar/1001/#',
        ?callable $reconnectSubscriber = null,
        ?\Hub\State\DeviceReportStore $deviceStore = null,
        ?IngestStats $stats = null,
        ?DashboardWritePolicy $dashboardWritePolicy = null,
        ?\Hub\Device\CommercialModelResolver $commercialModelResolver = null,
        ?\Hub\Registry\Denylist $denylist = null,
        private readonly int $idleTimeoutSeconds = 180,
        private readonly ?\Closure $layouts = null,
    ) {
        parent::__construct(
            $subscriber,
            $whitelist,
            $mqttBridge,
            $topicFilter,
            sourceName: 'qinglanst-radar',
            reconnectSubscriber: $reconnectSubscriber,
            deviceStore: $deviceStore,
            denylist: $denylist,
            commercialModelResolver: $commercialModelResolver,
        );
        $this->decoder = new PayloadDecoder();
        $this->normalizer = new MessageNormalizer();
        $this->stats = $stats ?? new IngestStats($topicFilter);
        $this->dashboardWritePolicy = $dashboardWritePolicy ?? new DashboardWritePolicy();
        $this->connections = new ConnectionAnnouncer($mqttBridge, $deviceStore);
        $this->activeDetections = new ActiveDetections();
    }

    /** Os tipos de área da planta do fabricante, pelo número com que vêm. */
    private const AREA_TYPES = [
        1 => 'custom',
        2 => 'bed',
        3 => 'interference',
        4 => 'door',
        5 => 'monitoring_bed',
        6 => 'alarm_area',
        7 => 'furniture',
    ];

    /**
     * Uma entrada ou saída de área leva o nome e o tipo que a planta guardada lhe dá. Sem planta
     * sincronizada, fica só o número.
     *
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function withArea(string $deviceKey, array $event): array
    {
        $areaId = $event['data']['areaId'] ?? null;
        if ($areaId === null || $this->layouts === null) {
            return $event;
        }

        foreach (($this->layouts)($deviceKey)['areas'] ?? [] as $area) {
            if ((int)$area['key'] === (int)$areaId) {
                $event['data'] += array_filter([
                    'areaName' => (string)$area['name'],
                    'areaType' => self::AREA_TYPES[(int)$area['type']] ?? null,
                ], static fn (?string $value): bool => $value !== null && $value !== '');
                break;
            }
        }

        return $event;
    }

    public function tick(float $timeout = 0.01): void
    {
        parent::tick($timeout);
        if ($this->clockNow() - $this->lastMaintenanceAt >= self::MAINTENANCE_INTERVAL_SECONDS) {
            $this->lastMaintenanceAt = $this->clockNow();
            $this->expireIdleRadars();
        }
    }

    /** O radar não abre sessão: está desligado quando se cala para lá do prazo. Público para os testes. */
    public function expireIdleRadars(): void
    {
        foreach ($this->deviceStore?->expireStaleDevices($this->idleTimeoutSeconds, 'radar') ?? [] as $imei) {
            $device = $this->whitelist->resolve($imei, 'qinglanst-radar');
            $this->connections->announce($imei, $device !== null ? $this->enrichWithCommercialName($device) : [
                'deviceType' => 'radar',
                'company' => self::RADAR_COMPANY,
            ], false);
        }
    }

    protected function handleMessage(string $topic, string $payload): void
    {
        $totalStart = hrtime(true);

        $parsedTopic = QinglanstTopic::parse($topic);
        if ($parsedTopic === null) {
            $this->stats->recordRejected('unsupported_topic', [
                'total' => hrtime(true) - $totalStart,
            ]);
            Logger::channel('hub')->warning("Ignoring unsupported Qinglanst topic {$topic}");
            return;
        }

        $resolveStart = hrtime(true);
        $device = $this->resolveDevice($parsedTopic);
        $resolveDuration = hrtime(true) - $resolveStart;
        if ($device === null) {
            $this->stats->recordRejected('unregistered_device', [
                'resolve' => $resolveDuration,
                'total' => hrtime(true) - $totalStart,
            ]);
            return;
        }

        $device = $this->enrichWithCommercialName($device);

        $jsonStart = hrtime(true);
        $upstreamPayload = $this->extractUpstreamPayload($payload);
        $jsonDuration = hrtime(true) - $jsonStart;
        if ($upstreamPayload === null) {
            $this->stats->recordRejected('invalid_json', [
                'resolve' => $resolveDuration,
                'json' => $jsonDuration,
                'total' => hrtime(true) - $totalStart,
            ]);
            Logger::channel('hub')->warning("Ignoring invalid Qinglanst JSON payload on {$topic}");
            return;
        }

        $messageType = $this->messageType($upstreamPayload);
        if ($messageType === null) {
            $this->stats->recordRejected('unsupported_payload_type', [
                'resolve' => $resolveDuration,
                'json' => $jsonDuration,
                'total' => hrtime(true) - $totalStart,
            ]);
            Logger::channel('hub')->warning("Ignoring unsupported Qinglanst payload type on {$topic}");
            return;
        }

        $deviceCode = trim((string)($upstreamPayload['deviceCode'] ?? $parsedTopic->deviceUid));
        $encodedPayload = (string)($upstreamPayload[$messageType] ?? '');

        $decodeStart = hrtime(true);
        $decoded = $this->decoder->decode($messageType, $encodedPayload, $deviceCode);
        $decodeDuration = hrtime(true) - $decodeStart;
        if ($decoded === null) {
            $this->stats->recordRejected('decode_failed', [
                'resolve' => $resolveDuration,
                'json' => $jsonDuration,
                'decode' => $decodeDuration,
                'total' => hrtime(true) - $totalStart,
            ]);
            Logger::channel('hub')->warning("Ignoring undecodable Qinglanst payload on {$topic}");
            return;
        }

        try {
            $normalizeStart = hrtime(true);
            $normalized = $this->normalizer->normalize($decoded, $parsedTopic, $device);
            $normalizeDuration = hrtime(true) - $normalizeStart;
        } catch (\Throwable $e) {
            $this->stats->recordRejected('normalize_failed', [
                'resolve' => $resolveDuration,
                'json' => $jsonDuration,
                'decode' => $decodeDuration,
                'total' => hrtime(true) - $totalStart,
            ]);
            Logger::channel('hub')->warning("Ignoring invalid Qinglanst message from {$parsedTopic->deviceUid}: {$e->getMessage()}");
            return;
        }

        // O `uid` do tópico só encontra o dispositivo; daqui em diante vale o IMEI canónico.
        $deviceKey = (string)$device['imei'];
        $deviceType = (string)$device['deviceType'];
        $licenseId = DeviceMetadata::normalizeLicenseId($device['licenseId'] ?? 0);
        $company = (string)($device['company'] ?? 'null');
        $nowMs = (int) floor(microtime(true) * 1000);

        // A trama que chega é a mensagem original do radar, republicada em `raw` para debugging.
        $raw = [
            'direction' => 'uplink',
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'device' => DeviceDescriptor::of($deviceKey, $device),
            'data' => $upstreamPayload,
            'debug' => [
                'protocol' => 'qinglanst-radar',
                'transport' => 'mqtt',
                'encoding' => 'json',
                'payload' => $upstreamPayload,
                'sourceTopic' => $topic,
            ],
        ];
        // O MQTT leva tudo; o histórico da dashboard, uma amostra.
        $this->mqttBridge->publishRaw($deviceKey, $raw, $deviceType, $licenseId, $company);
        if ($this->deviceStore !== null && $this->dashboardWritePolicy->shouldStoreRaw($deviceKey, $nowMs)) {
            $this->deviceStore->append($deviceKey, 'raw', $raw + ['deviceType' => $deviceType, 'licenseId' => $licenseId]);
        }

        $redisSeenDuration = 0;
        if ($this->deviceStore !== null && $this->dashboardWritePolicy->shouldUpdateSeen($deviceKey, $nowMs)) {
            $redisSeenStart = hrtime(true);
            $cameOnline = $this->deviceStore->deviceSeen($deviceKey, [
                'supplier' => (string)$device['supplier'],
                'model' => (string)$device['model'],
                'deviceType' => $deviceType,
                'licenseId' => $licenseId,
                'company' => $company,
                'protocol' => 'qinglanst-radar',
                'transport' => 'mqtt',
                'online' => '1',
            ]);
            if ($cameOnline) {
                $this->connections->announce($deviceKey, $device, true);
            }
            $redisSeenDuration = hrtime(true) - $redisSeenStart;
        }

        $mqttTelemetryDuration = 0;
        $redisTelemetryDuration = 0;
        $mqttEventDuration = 0;
        $redisEventDuration = 0;
        $publishedTelemetry = false;
        $publishedEvent = false;

        // O estrangulamento no Redis é por capacidade: as que chegam na mesma mensagem mudam
        // a ritmos diferentes.
        foreach ($normalized['telemetry'] as $capability => $telemetry) {
            $mqttTelemetryStart = hrtime(true);
            $this->mqttBridge->publishTelemetry($deviceKey, $telemetry, $deviceType, $licenseId, $company);
            $mqttTelemetryDuration += hrtime(true) - $mqttTelemetryStart;

            if ($this->deviceStore !== null) {
                $redisTelemetryStart = hrtime(true);
                $this->deviceStore->append($deviceKey, 'telemetry', array_merge($telemetry, [
                    'deviceType' => $deviceType,
                    'licenseId' => $licenseId,
                ]));
                $redisTelemetryDuration += hrtime(true) - $redisTelemetryStart;
            }
            $publishedTelemetry = true;
        }

        foreach ($this->activeDetections->fresh($deviceKey, $messageType, $normalized['events']) as $event) {
            $event = $this->withArea($deviceKey, $event);
            $mqttEventStart = hrtime(true);
            $this->mqttBridge->publishEvent($deviceKey, $event, $deviceType, $licenseId, $company);
            $mqttEventDuration += hrtime(true) - $mqttEventStart;

            $redisEventStart = hrtime(true);
            $this->deviceStore?->append($deviceKey, 'events', array_merge($event, [
                'deviceType' => $deviceType,
                'licenseId' => $licenseId,
            ]));
            $redisEventDuration += hrtime(true) - $redisEventStart;
            $publishedEvent = true;
        }

        $this->stats->recordAccepted($messageType, $publishedTelemetry, $publishedEvent, [
            'resolve' => $resolveDuration,
            'json' => $jsonDuration,
            'decode' => $decodeDuration,
            'normalize' => $normalizeDuration,
            'redis_seen' => $redisSeenDuration,
            'mqtt_telemetry' => $mqttTelemetryDuration,
            'redis_telemetry' => $redisTelemetryDuration,
            'mqtt_event' => $mqttEventDuration,
            'redis_event' => $redisEventDuration,
            'total' => hrtime(true) - $totalStart,
        ]);
    }

    /**
     * @return array{imei: string, supplier: string, model: string, deviceType: string, licenseId: int, company?: string}|null
     */
    private function resolveDevice(QinglanstTopic $topic): ?array
    {
        $deviceUid = $topic->deviceUid;

        $resolved = $this->whitelist->resolve($deviceUid, 'qinglanst-radar');
        if ($resolved !== null) {
            return $resolved;
        }

        // A licença vem do tópico `radar/{licenseId}/{uid}`: é o único campo do assistente de
        // registo que o protocolo não dá.
        $this->recordUnauthorizedDevice(
            $deviceUid,
            'qinglanst-radar',
            ident: $deviceUid,
            licenseId: (int)$topic->licenseId,
            company: self::RADAR_COMPANY
        );
        Logger::channel('hub')->warning("Ignoring unregistered Qinglanst device uid={$deviceUid}");
        return null;
    }

    /** @return array<string, mixed>|null */
    private function extractUpstreamPayload(string $payload): ?array
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return null;
        }

        $wrapped = $decoded['payload'] ?? $decoded;
        return is_array($wrapped) ? $wrapped : null;
    }

    /** @param array<string, mixed> $payload */
    private function messageType(array $payload): ?string
    {
        $presentTypes = [];
        foreach (self::SUPPORTED_TYPES as $type) {
            if (!empty($payload[$type])) {
                $presentTypes[] = $type;
            }
        }

        return count($presentTypes) === 1 ? $presentTypes[0] : null;
    }
}
