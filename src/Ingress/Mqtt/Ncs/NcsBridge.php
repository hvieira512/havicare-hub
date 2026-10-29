<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Ncs;

use Hub\Domain\DeviceMetadata;
use Hub\Ingress\Mqtt\MqttBridgeBase;
use Hub\Log\Logger;
use Hub\State\DeviceRuntimeStore;

final class NcsBridge extends MqttBridgeBase
{
    /** As cópias do mesmo clique chegam pelos gateways com milissegundos de diferença; um clique real repetido nunca vem a menos de um segundo. */
    private const DUPLICATE_WINDOW_SECONDS = 0.5;

    private readonly MessageNormalizer $normalizer;

    /** @var array<string, float> */
    private array $lastCallAt = [];

    public function __construct(
        \PhpMqtt\Client\MqttClient $subscriber,
        \Hub\Registry\Whitelist $whitelist,
        \Hub\Device\HubMqttBridge $mqttBridge,
        string $topicFilter = '/voerka/#',
        ?callable $reconnectSubscriber = null,
        ?\Hub\State\DeviceReportStore $deviceStore = null,
        ?\Hub\Device\CommercialModelResolver $commercialModelResolver = null,
        ?\Hub\Registry\Denylist $denylist = null,
        ?callable $clock = null,
    ) {
        parent::__construct(
            $subscriber,
            $whitelist,
            $mqttBridge,
            $topicFilter,
            sourceName: 'ncs',
            reconnectSubscriber: $reconnectSubscriber,
            deviceStore: $deviceStore,
            denylist: $denylist,
            clock: $clock,
            commercialModelResolver: $commercialModelResolver,
        );
        $this->normalizer = new MessageNormalizer();
    }

    public function handleReceivedMessage(string $topic, string $payload): void
    {
        $parsedTopic = NcsTopic::parse($topic);
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
            // A licença no âmbito do tópico é só uma pista para o assistente de registo; a
            // atribuição sai da whitelist.
            $this->recordUnauthorizedDevice(
                $from,
                'voerka-ncs',
                ident: $from,
                licenseId: ctype_digit($parsedTopic->scope) ? (int)$parsedTopic->scope : 0
            );
            $this->warnRepeatedly("unregistered:{$from}", "Ignoring unregistered NCS source from={$from}");
            return;
        }

        $device = $this->enrichWithCommercialName($device);

        try {
            $normalized = $this->normalizer->normalize($parsedTopic, $message, $device);
        } catch (\Throwable $e) {
            Logger::channel('hub')->warning("Ignoring invalid NCS message from={$from}: {$e->getMessage()}");
            return;
        }

        $deviceKey = (string)$device['imei'];
        $deviceType = (string)$device['deviceType'];
        $licenseId = DeviceMetadata::normalizeLicenseId($device['licenseId'] ?? 0);
        $company = (string)($device['company'] ?? 'null');

        $this->mqttBridge->publishRaw($deviceKey, $normalized['raw'], $deviceType, $licenseId, $company);
        $this->deviceStore?->deviceSeen($deviceKey, DeviceRuntimeStore::seenFields($device, 'voerka-ncs', 'mqtt'));
        $this->deviceStore?->append($deviceKey, 'raw', array_merge($normalized['raw'], [
            'deviceType' => $deviceType,
            'licenseId' => $licenseId,
        ]));

        if (isset($normalized['status']) && is_array($normalized['status'])) {
            $retain = ((string)($normalized['status']['state'] ?? '')) !== 'error';
            $this->mqttBridge->publishStatus($deviceKey, $normalized['status'], $retain, $deviceType, $licenseId, $company);
            if (($normalized['status']['state'] ?? '') === 'offline') {
                $this->deviceStore?->deviceOffline($deviceKey);
            }
        }

        if (isset($normalized['event']) && is_array($normalized['event']) && !$this->isCopyFromAnotherGateway($licenseId, $normalized['event'])) {
            $this->mqttBridge->publishAndStore($this->deviceStore, 'events', $deviceKey, $normalized['event'], $deviceType, $licenseId, $company);
        }
    }

    /** @param array<string, mixed> $event */
    private function isCopyFromAnotherGateway(int|string $licenseId, array $event): bool
    {
        $pagerId = $event['data']['pagerId'] ?? null;
        if ($pagerId === null) {
            return false;
        }

        $now = $this->clockNow();
        $this->lastCallAt = array_filter($this->lastCallAt, static fn (float $at): bool => $now - $at < self::DUPLICATE_WINDOW_SECONDS);
        $key = "{$licenseId}|{$pagerId}|{$event['type']}";
        if (isset($this->lastCallAt[$key])) {
            $gapMs = (int)round(($now - $this->lastCallAt[$key]) * 1000);
            Logger::channel('hub')->info("Dropping NCS {$event['type']} for pager {$pagerId} already heard by another gateway {$gapMs} ms earlier");
            return true;
        }
        $this->lastCallAt[$key] = $now;

        return false;
    }
}
