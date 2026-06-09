<?php

namespace App\Hub;

use App\Log\Logger;
use App\Mqtt\SimpleClient;

class HubDownlinkSubscriber
{
    private SimpleClient $subscriber;
    private DeviceHubServer $hubServer;
    private string $topicPrefix;

    public function __construct(SimpleClient $subscriber, DeviceHubServer $hubServer, string $topicPrefix = '')
    {
        $this->subscriber = $subscriber;
        $this->hubServer = $hubServer;
        $this->topicPrefix = trim($topicPrefix, '/');
    }

    public function start(): void
    {
        $filter = $this->topic('devices/+/downlink');
        $this->subscriber->subscribe($filter, function (string $topic, string $payload): void {
            $this->handle($topic, $payload);
        });
        Logger::channel('hub')->info("MQTT downlink subscribed to {$filter}");
    }

    public function tick(float $timeout = 0.01): void
    {
        $this->subscriber->loopOnce($timeout);
    }

    private function handle(string $topic, string $payload): void
    {
        $imei = $this->imeiFromTopic($topic);
        if ($imei === null) {
            Logger::channel('hub')->warning("Ignoring downlink on unexpected topic {$topic}");
            return;
        }

        $decoded = json_decode($payload, true);
        $bytes = RawPayload::bytesFromDownlink(is_array($decoded) ? $decoded : $payload);
        if ($bytes === null) {
            Logger::channel('hub')->warning("Ignoring malformed downlink for IMEI={$imei}");
            return;
        }

        if (!$this->hubServer->sendDownlink($imei, $bytes)) {
            Logger::channel('hub')->warning("Downlink dropped because IMEI={$imei} is offline");
        }
    }

    private function imeiFromTopic(string $topic): ?string
    {
        $base = $this->topicPrefix === '' ? $topic : preg_replace(
            '/^' . preg_quote($this->topicPrefix, '/') . '\\//',
            '',
            $topic
        );
        if (!is_string($base)) {
            return null;
        }

        $parts = explode('/', trim($base, '/'));
        if (count($parts) !== 3 || $parts[0] !== 'devices' || $parts[2] !== 'downlink') {
            return null;
        }

        return $parts[1] !== '' ? $parts[1] : null;
    }

    private function topic(string $topic): string
    {
        $topic = trim($topic, '/');
        return $this->topicPrefix === '' ? $topic : $this->topicPrefix . '/' . $topic;
    }
}
