<?php

namespace Hub\Ingress\Mqtt\Ncs;

use Hub\Log\Logger;

final class Bridge extends \Hub\Ingress\Mqtt\Bridge
{
    private readonly ?MessageNormalizer $normalizer;

    public function __construct(
        \PhpMqtt\Client\MqttClient $subscriber,
        \Hub\Registry\Whitelist $whitelist,
        \Hub\HubMqttBridge $mqttBridge,
        string $topicFilter = '/voerka/#',
        ?callable $reconnectSubscriber = null,
        ?\Hub\Dashboard\DashboardStore $dashboardStore = null,
        ?MessageNormalizer $normalizer = null,
    ) {
        parent::__construct(
            $subscriber,
            $whitelist,
            $mqttBridge,
            $topicFilter,
            sourceName: 'ncs',
            reconnectSubscriber: $reconnectSubscriber,
            dashboardStore: $dashboardStore,
        );
        $this->normalizer = $normalizer;
    }

    protected function handleMessage(string $topic, string $payload): void
    {
        $parsedTopic = Topic::parse($topic);
        if ($parsedTopic === null) {
            Logger::channel('hub')->warning("Ignoring unsupported NCS topic {$topic}");
            return;
        }

        if (!in_array($parsedTopic->kind, ['status', 'events'], true)) {
            Logger::channel('hub')->info("Ignoring NCS {$parsedTopic->kind} topic {$topic} in phase 1");
            return;
        }

        $message = json_decode($payload, true);
        if (!is_array($message)) {
            Logger::channel('hub')->warning("Ignoring malformed NCS JSON on {$topic}");
            return;
        }

        $from = trim((string)($message['from'] ?? ''));
        if ($from === '') {
            Logger::channel('hub')->warning("Ignoring NCS message without from on {$topic}");
            return;
        }

        if ($from !== $parsedTopic->sourceId) {
            Logger::channel('hub')->warning("Ignoring NCS message with source mismatch topic={$parsedTopic->sourceId} payload={$from}");
            return;
        }

        $device = $this->whitelist->resolve($from, 'ncs');
        if ($device === null || trim((string)($device['licenseId'] ?? '')) === '') {
            Logger::channel('hub')->warning("Ignoring unregistered NCS source from={$from}");
            return;
        }

        try {
            $normalized = ($this->normalizer ?? new MessageNormalizer())->normalize($parsedTopic, $message, $device);
        } catch (\Throwable $e) {
            Logger::channel('hub')->warning("Ignoring invalid NCS message from={$from}: {$e->getMessage()}");
            return;
        }

        $deviceKey = (string)$device['imei'];
        $deviceType = (string)$device['deviceType'];
        $licenseId = (string)$device['licenseId'];
        $company = (string)($device['company'] ?? 'null');

        $this->mqttBridge->publishRaw($deviceKey, $normalized['raw'], $deviceType, $licenseId, $company);
        $this->dashboardStore?->deviceSeen($deviceKey, [
            'supplier' => (string)$device['supplier'],
            'model' => (string)$device['model'],
            'deviceType' => $deviceType,
            'licenseId' => $licenseId,
            'company' => $company,
            'sourceSystem' => (string)($device['sourceSystem'] ?? ''),
            'sourceDeviceId' => (string)($device['sourceDeviceId'] ?? ''),
            'protocol' => 'voerka-ncs',
            'transport' => 'mqtt',
            'online' => '1',
        ]);
        $this->dashboardStore?->append($deviceKey, 'raw', array_merge($normalized['raw'], [
            'deviceType' => $deviceType,
            'licenseId' => $licenseId,
        ]));

        if (isset($normalized['status']) && is_array($normalized['status'])) {
            $retain = ((string)($normalized['status']['state'] ?? '')) !== 'error';
            $this->mqttBridge->publishStatus($deviceKey, $normalized['status'], $retain, $deviceType, $licenseId, $company);
            if (($normalized['status']['state'] ?? '') === 'offline') {
                $this->dashboardStore?->deviceOffline($deviceKey);
            }
        }

        if (isset($normalized['event']) && is_array($normalized['event'])) {
            $this->mqttBridge->publishEvent($deviceKey, $normalized['event'], $deviceType, $licenseId, $company);
            $this->dashboardStore?->append($deviceKey, 'events', array_merge($normalized['event'], [
                'deviceType' => $deviceType,
                'licenseId' => $licenseId,
            ]));
        }

        if (isset($normalized['telemetry']) && is_array($normalized['telemetry'])) {
            $this->mqttBridge->publishTelemetry($deviceKey, $normalized['telemetry'], $deviceType, $licenseId, $company);
            $this->dashboardStore?->append($deviceKey, 'telemetry', array_merge($normalized['telemetry'], [
                'deviceType' => $deviceType,
                'licenseId' => $licenseId,
            ]));
        }
    }
}
