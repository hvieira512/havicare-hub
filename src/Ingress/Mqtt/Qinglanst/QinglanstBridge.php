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
    private readonly ConnectionAnnouncer $connections;
    private readonly ActiveDetections $activeDetections;
    /** O varrimento dos radares calados corre no máximo de dez em dez segundos. */
    private const MAINTENANCE_INTERVAL_SECONDS = 10.0;
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
        private readonly int $seenIntervalMs = 5000,
        private readonly int $rawHistorySampleMs = 30000,
        ?\Hub\Device\CommercialModelResolver $commercialModelResolver = null,
        ?\Hub\Registry\Denylist $denylist = null,
        private readonly int $idleTimeoutSeconds = 180,
        private readonly ?\Closure $layouts = null,
        private readonly ?\Closure $onMessage = null,
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
        $this->connections = new ConnectionAnnouncer($mqttBridge, $deviceStore);
        $this->activeDetections = new ActiveDetections();
    }

    /** Os tipos de área da planta do fabricante, pelo número com que vêm, na enumeração do contrato. */
    public const AREA_TYPES = [
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
                    'areaName' => $area['name'] ?? null,
                    'areaType' => $area['type'] ?? null,
                ], static fn (?string $value): bool => $value !== null && $value !== '');
                break;
            }
        }

        return $event;
    }

    public function tick(float $timeout = 0.01): void
    {
        parent::tick($timeout);
        if ($this->allow('maintenance', self::MAINTENANCE_INTERVAL_SECONDS)) {
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

    public function handleReceivedMessage(string $topic, string $payload): void
    {
        $parsedTopic = QinglanstTopic::parse($topic);
        if ($parsedTopic === null) {
            Logger::channel('hub')->warning("Ignoring unsupported Qinglanst topic {$topic}");
            return;
        }

        $device = $this->resolveDevice($parsedTopic);
        if ($device === null) {
            return;
        }

        $device = $this->enrichWithCommercialName($device);

        $upstreamPayload = $this->extractUpstreamPayload($payload);
        if ($upstreamPayload === null) {
            Logger::channel('hub')->warning("Ignoring invalid Qinglanst JSON payload on {$topic}");
            return;
        }

        $messageType = $this->messageType($upstreamPayload);
        if ($messageType === null) {
            Logger::channel('hub')->warning("Ignoring unsupported Qinglanst payload type on {$topic}");
            return;
        }

        $deviceCode = trim((string)($upstreamPayload['deviceCode'] ?? $parsedTopic->deviceUid));
        $encodedPayload = (string)($upstreamPayload[$messageType] ?? '');

        $decoded = $this->decoder->decode($messageType, $encodedPayload, $deviceCode);
        if ($decoded === null) {
            Logger::channel('hub')->warning("Ignoring undecodable Qinglanst payload on {$topic}");
            return;
        }

        try {
            $normalized = $this->normalizer->normalize($decoded, $parsedTopic, $device);
        } catch (\Throwable $e) {
            Logger::channel('hub')->warning("Ignoring invalid Qinglanst message from {$parsedTopic->deviceUid}: {$e->getMessage()}");
            return;
        }

        // O `uid` do tópico só encontra o dispositivo; daqui em diante vale o IMEI canónico.
        $deviceKey = (string)$device['imei'];
        // Uma mensagem é o radar ligado: o que espera por ele na fila da cloud pode sair.
        if ($this->onMessage !== null) {
            ($this->onMessage)($deviceKey);
        }
        $deviceType = (string)$device['deviceType'];
        $licenseId = DeviceMetadata::normalizeLicenseId($device['licenseId'] ?? 0);
        $company = (string)($device['company'] ?? 'null');

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
        if ($this->deviceStore !== null && $this->allow('raw:' . $deviceKey, $this->rawHistorySampleMs / 1000)) {
            $this->deviceStore->append($deviceKey, 'raw', $raw + ['deviceType' => $deviceType, 'licenseId' => $licenseId]);
        }

        if ($this->deviceStore !== null && $this->allow('seen:' . $deviceKey, $this->seenIntervalMs / 1000)) {
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
        }

        // O estrangulamento no Redis é por capacidade: as que chegam na mesma mensagem mudam
        // a ritmos diferentes.
        foreach ($normalized['telemetry'] as $capability => $telemetry) {
            $this->mqttBridge->publishTelemetry($deviceKey, $telemetry, $deviceType, $licenseId, $company);
            $this->deviceStore?->append($deviceKey, 'telemetry', array_merge($telemetry, [
                'deviceType' => $deviceType,
                'licenseId' => $licenseId,
            ]));
        }

        foreach ($this->activeDetections->fresh($deviceKey, $messageType, $normalized['events']) as $event) {
            $event = $this->withArea($deviceKey, $event);
            $this->mqttBridge->publishEvent($deviceKey, $event, $deviceType, $licenseId, $company);
            $this->deviceStore?->append($deviceKey, 'events', array_merge($event, [
                'deviceType' => $deviceType,
                'licenseId' => $licenseId,
            ]));
        }
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
