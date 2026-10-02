<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Ncs;

use Hub\Domain\DeviceMetadata;
use Hub\Ingress\Mqtt\MqttBridgeBase;
use Hub\Log\Logger;

final class NcsBridge extends MqttBridgeBase
{
    private readonly MessageNormalizer $normalizer;
    private readonly ?\Hub\Device\CommercialModelResolver $commercialModelResolver;

    public function __construct(
        \PhpMqtt\Client\MqttClient $subscriber,
        \Hub\Registry\Whitelist $whitelist,
        \Hub\Device\HubMqttBridge $mqttBridge,
        string $topicFilter = '/voerka/#',
        ?callable $reconnectSubscriber = null,
        ?\Hub\State\DeviceReportStore $deviceStore = null,
        ?\Hub\Device\CommercialModelResolver $commercialModelResolver = null,
        ?\Hub\Registry\Denylist $denylist = null,
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
        );
        $this->normalizer = new MessageNormalizer();
        $this->commercialModelResolver = $commercialModelResolver;
    }

    protected function handleMessage(string $topic, string $payload): void
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
            // O âmbito do tópico é livre do lado da Voerka, e um gateway configurado com a
            // licença dá à dashboard o campo que o protocolo não diz. Só uma pista para o
            // assistente de registo: a atribuição continua a sair da whitelist.
            $this->recordUnauthorizedDevice(
                $from,
                'voerka-ncs',
                ident: $from,
                licenseId: ctype_digit($parsedTopic->scope) ? (int)$parsedTopic->scope : 0
            );
            Logger::channel('hub')->warning("Ignoring unregistered NCS source from={$from}");
            return;
        }

        $device = $this->enrichDevice($device);

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
        $this->deviceStore?->deviceSeen($deviceKey, [
            'supplier' => (string)$device['supplier'],
            'model' => (string)$device['model'],
            'deviceType' => $deviceType,
            'licenseId' => $licenseId,
            'company' => $company,
            'protocol' => 'voerka-ncs',
            'transport' => 'mqtt',
            'online' => '1',
        ]);
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

        if (isset($normalized['event']) && is_array($normalized['event'])) {
            $this->mqttBridge->publishEvent($deviceKey, $normalized['event'], $deviceType, $licenseId, $company);
            $this->deviceStore?->append($deviceKey, 'events', array_merge($normalized['event'], [
                'deviceType' => $deviceType,
                'licenseId' => $licenseId,
            ]));
        }
    }

    /**
     * @param array<string, mixed> $device
     * @return array<string, mixed>
     */
    private function enrichDevice(array $device): array
    {
        return $this->enrichWithCommercialName($device, $this->commercialModelResolver);
    }
}
