<?php

namespace Hub\Ingress\Mqtt\Qinglanst;

use Hub\Log\Logger;

final class Bridge extends \Hub\Ingress\Mqtt\Bridge
{
    private readonly ?PayloadDecoder $decoder;
    private readonly ?MessageNormalizer $normalizer;

    public function __construct(
        \PhpMqtt\Client\MqttClient $subscriber,
        \Hub\Registry\Whitelist $whitelist,
        \Hub\HubMqttBridge $mqttBridge,
        string $topicFilter = 'radar/1001/#',
        ?callable $reconnectSubscriber = null,
        ?\Hub\Dashboard\DashboardStore $dashboardStore = null,
        ?PayloadDecoder $decoder = null,
        ?MessageNormalizer $normalizer = null,
    ) {
        parent::__construct(
            $subscriber,
            $whitelist,
            $mqttBridge,
            $topicFilter,
            sourceName: 'qinglanst',
            reconnectSubscriber: $reconnectSubscriber,
            dashboardStore: $dashboardStore,
        );
        $this->decoder = $decoder;
        $this->normalizer = $normalizer;
    }

    protected function handleMessage(string $topic, string $payload): void
    {
        $parsedTopic = Topic::parse($topic);
        if ($parsedTopic === null) {
            Logger::channel('hub')->warning("Ignoring unsupported Qinglanst topic {$topic}");
            return;
        }

        $device = $this->resolveDevice($parsedTopic);
        if ($device === null) {
            return;
        }

        $decoded = ($this->decoder ?? new PayloadDecoder())->decode($payload, $parsedTopic->deviceUid);
        if ($decoded === null) {
            Logger::channel('hub')->warning("Ignoring undecodable Qinglanst payload on {$topic}");
            return;
        }

        try {
            $normalized = ($this->normalizer ?? new MessageNormalizer())->normalize($decoded, $parsedTopic, $device);
        } catch (\Throwable $e) {
            Logger::channel('hub')->warning("Ignoring invalid Qinglanst message from {$parsedTopic->deviceUid}: {$e->getMessage()}");
            return;
        }

        $deviceKey = (string)$device['imei'];
        $deviceType = (string)$device['deviceType'];
        $licenseId = (string)$device['licenseId'];
        $company = (string)($device['company'] ?? 'null');

        $this->dashboardStore?->deviceSeen($deviceKey, [
            'supplier' => (string)$device['supplier'],
            'model' => (string)$device['model'],
            'deviceType' => $deviceType,
            'licenseId' => $licenseId,
            'company' => $company,
            'protocol' => 'qinglanst-radar',
            'transport' => 'mqtt',
            'online' => '1',
        ]);

        if (isset($normalized['telemetry']) && is_array($normalized['telemetry'])) {
            $this->mqttBridge->publishTelemetry($deviceKey, $normalized['telemetry'], $deviceType, $licenseId, $company);
            $this->dashboardStore?->append($deviceKey, 'telemetry', array_merge($normalized['telemetry'], [
                'deviceType' => $deviceType,
                'licenseId' => $licenseId,
            ]));
        }

        if (isset($normalized['event']) && is_array($normalized['event'])) {
            $this->mqttBridge->publishEvent($deviceKey, $normalized['event'], $deviceType, $licenseId, $company);
            $this->dashboardStore?->append($deviceKey, 'events', array_merge($normalized['event'], [
                'deviceType' => $deviceType,
                'licenseId' => $licenseId,
            ]));
        }
    }

    /**
     * @return array{imei: string, supplier: string, model: string, deviceType: string, licenseId: string, company?: string}|null
     */
    private function resolveDevice(Topic $topic): ?array
    {
        $deviceUid = $topic->deviceUid;
        $licenseId = $topic->licenseId;

        $resolved = $this->whitelist->resolve($deviceUid, 'qinglanst');
        if ($resolved !== null) {
            return $resolved;
        }

        $resolved = $this->whitelist->resolve($deviceUid, 'qinglanst', $deviceUid);
        if ($resolved !== null) {
            return $resolved;
        }

        Logger::channel('hub')->warning("Ignoring unregistered Qinglanst device uid={$deviceUid}");
        return null;
    }
}
