<?php

namespace Hub\Ingress\Mqtt\Qinglanst;

use Hub\Domain\DeviceMetadata;

use Hub\Log\Logger;

final class Bridge extends \Hub\Ingress\Mqtt\Bridge
{
    private readonly ?PayloadDecoder $decoder;
    private readonly ?MessageNormalizer $normalizer;
    private readonly ?\Hub\CommercialModelResolver $commercialModelResolver;
    private readonly IngestStats $stats;
    private readonly DashboardWritePolicy $dashboardWritePolicy;
    private const SUPPORTED_TYPES = ['position', 'heartbreath', 'posstatics', 'hbstatics'];

    public function __construct(
        \PhpMqtt\Client\MqttClient $subscriber,
        \Hub\Registry\Whitelist $whitelist,
        \Hub\HubMqttBridge $mqttBridge,
        string $topicFilter = 'radar/1001/#',
        ?callable $reconnectSubscriber = null,
        ?\Hub\Dashboard\DashboardStoreContract $dashboardStore = null,
        ?PayloadDecoder $decoder = null,
        ?MessageNormalizer $normalizer = null,
        ?IngestStats $stats = null,
        ?DashboardWritePolicy $dashboardWritePolicy = null,
        ?\Hub\CommercialModelResolver $commercialModelResolver = null,
    ) {
        parent::__construct(
            $subscriber,
            $whitelist,
            $mqttBridge,
            $topicFilter,
            sourceName: 'qinglanst-radar',
            reconnectSubscriber: $reconnectSubscriber,
            dashboardStore: $dashboardStore,
        );
        $this->decoder = $decoder;
        $this->normalizer = $normalizer;
        $this->commercialModelResolver = $commercialModelResolver;
        $this->stats = $stats ?? new IngestStats($topicFilter);
        $this->dashboardWritePolicy = $dashboardWritePolicy ?? new DashboardWritePolicy();
    }

    protected function handleMessage(string $topic, string $payload): void
    {
        $totalStart = hrtime(true);

        $parsedTopic = Topic::parse($topic);
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

        $device = $this->enrichDevice($device);

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
        $decoded = ($this->decoder ?? new PayloadDecoder())->decode($messageType, $encodedPayload, $deviceCode);
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
            $normalized = ($this->normalizer ?? new MessageNormalizer())->normalize($decoded, $parsedTopic, $device);
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

        $dashboardKey = (string)$device['imei'];
        $topicDeviceKey = $parsedTopic->deviceUid;
        $deviceType = (string)$device['deviceType'];
        $licenseId = DeviceMetadata::normalizeLicenseId($device['licenseId'] ?? 0);
        $company = (string)($device['company'] ?? 'null');
        $nowMs = (int) floor(microtime(true) * 1000);

        $redisSeenDuration = 0;
        if ($this->dashboardStore !== null && $this->dashboardWritePolicy->shouldUpdateSeen($dashboardKey, $nowMs)) {
            $redisSeenStart = hrtime(true);
            $this->dashboardStore->deviceSeen($dashboardKey, [
                'supplier' => (string)$device['supplier'],
                'model' => (string)$device['model'],
                'deviceType' => $deviceType,
                'licenseId' => $licenseId,
                'company' => $company,
                'protocol' => 'qinglanst-radar',
                'transport' => 'mqtt',
                'online' => '1',
            ]);
            $redisSeenDuration = hrtime(true) - $redisSeenStart;
        }

        $mqttTelemetryDuration = 0;
        $redisTelemetryDuration = 0;
        $mqttEventDuration = 0;
        $redisEventDuration = 0;
        $publishedTelemetry = false;
        $publishedEvent = false;

        // Uma mensagem mede mais do que uma coisa, por isso o normalizador devolve um mapa
        // de capacidade para leitura e uma lista de alarmes. O estrangulamento da escrita
        // no Redis é por capacidade: a frequência cardíaca e o estado de sono chegam na
        // mesma mensagem mas mudam a ritmos diferentes.
        foreach ($normalized['telemetry'] as $capability => $telemetry) {
            $mqttTelemetryStart = hrtime(true);
            $this->mqttBridge->publishTelemetry($topicDeviceKey, $telemetry, $deviceType, $licenseId, $company);
            $mqttTelemetryDuration += hrtime(true) - $mqttTelemetryStart;

            if (
                $this->dashboardStore !== null
                && $this->dashboardWritePolicy->shouldStoreTelemetry($dashboardKey, (string)$capability, $nowMs)
            ) {
                $redisTelemetryStart = hrtime(true);
                $this->dashboardStore->append($dashboardKey, 'telemetry', array_merge($telemetry, [
                    'deviceType' => $deviceType,
                    'licenseId' => $licenseId,
                ]));
                $redisTelemetryDuration += hrtime(true) - $redisTelemetryStart;
            }
            $publishedTelemetry = true;
        }

        foreach ($normalized['events'] as $event) {
            $mqttEventStart = hrtime(true);
            $this->mqttBridge->publishEvent($topicDeviceKey, $event, $deviceType, $licenseId, $company);
            $mqttEventDuration += hrtime(true) - $mqttEventStart;

            $redisEventStart = hrtime(true);
            $this->dashboardStore?->append($dashboardKey, 'events', array_merge($event, [
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
     * @return array{imei: string, supplier: string, model: string, deviceType: string, licenseId: string, company?: string}|null
     */
    private function resolveDevice(Topic $topic): ?array
    {
        $deviceUid = $topic->deviceUid;

        $resolved = $this->whitelist->resolve($deviceUid, 'qinglanst-radar');
        if ($resolved !== null) {
            return $resolved;
        }

        $resolved = $this->whitelist->resolve($deviceUid, 'qinglanst-radar', $deviceUid);
        if ($resolved !== null) {
            return $resolved;
        }

        // A licença é o que o UID não diz, e o tópico é `radar/{licenca}/{uid}`. Sem ela,
        // quem lê a notificação não sabe a que licença registar o radar que apareceu --
        // e é o único campo do assistente que não se deduz do protocolo.
        $this->recordUnauthorizedDevice(
            $deviceUid,
            'qinglanst-radar',
            ident: $deviceUid,
            licenseId: (int)$topic->licenseId
        );
        Logger::channel('hub')->warning("Ignoring unregistered Qinglanst device uid={$deviceUid}");
        return null;
    }

    /**
     * @param array{imei: string, supplier: string, model: string, deviceType: string, licenseId: string, company?: string, commercialName?: string} $device
     * @return array{imei: string, supplier: string, model: string, deviceType: string, licenseId: string, company?: string, commercialName?: string}
     */
    private function enrichDevice(array $device): array
    {
        if (($device['commercialName'] ?? '') !== '') {
            return $device;
        }

        $commercialName = $this->commercialModelResolver?->resolveCommercialName(
            (string)($device['supplier'] ?? ''),
            (string)($device['model'] ?? '')
        ) ?? '';

        if ($commercialName !== '') {
            $device['commercialName'] = $commercialName;
        }

        return $device;
    }

    private function extractUpstreamPayload(string $payload): ?array
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return null;
        }

        $wrapped = $decoded['payload'] ?? $decoded;
        return is_array($wrapped) ? $wrapped : null;
    }

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
