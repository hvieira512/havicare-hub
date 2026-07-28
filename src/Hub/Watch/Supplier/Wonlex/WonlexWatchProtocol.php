<?php

namespace Hub\Watch\Supplier\Wonlex;

use Hub\DeviceEventDecoder;
use Hub\DeviceSession;
use Hub\Protocol\Adapter\DeviceAdapterInterface;
use Hub\Watch\AbstractWatchProtocol;
use Hub\Watch\WatchResponse;

final class WonlexWatchProtocol extends AbstractWatchProtocol
{
    /** @var callable(DeviceSession): array<string, mixed>|null */
    private $stateProvider;

    public function __construct(
        DeviceAdapterInterface $adapter,
        DeviceEventDecoder $eventDecoder,
        ?callable $stateProvider = null,
    ) {
        parent::__construct($adapter, $eventDecoder);
        $this->stateProvider = $stateProvider;
    }

    /**
     * @return array<int, WatchResponse>
     */
    protected function responsesForDecoded(DeviceSession $session, array $decoded): array
    {
        $type = (string)($decoded['type'] ?? '');
        $responses = [];
        $timestamp = (int) round(microtime(true) * 1000);
        $state = $this->state($session);

        if ($type === 'login') {
            $responses[] = new WatchResponse($this->encodeOutgoing([
                'type' => 'login',
                'ident' => $this->replyIdent($decoded['ident'] ?? null),
                'ref' => 's:reply',
                'imei' => $session->imei,
                'data' => [
                    'type' => 'login',
                    'imei' => $session->imei,
                    'bindStatus' => (int)($state['bindStatus'] ?? 0),
                    'timestamp' => $timestamp,
                ],
                'timestamp' => $timestamp,
            ]));

            return $responses;
        }

        if ($type === 'heartbeat') {
            $responses[] = new WatchResponse($this->encodeOutgoing([
                'type' => 'heartbeat',
                'ident' => $this->replyIdent($decoded['ident'] ?? null),
                'ref' => 's:reply',
                'imei' => $session->imei,
                'data' => [
                    'type' => 'heartbeat',
                    'imei' => $session->imei,
                    'deviceModel' => $session->model,
                    'timestamp' => $timestamp,
                ],
                'timestamp' => $timestamp,
            ]));

            return $responses;
        }

        if (($decoded['ref'] ?? '') !== 'w:update') {
            return $responses;
        }

        if ($type === '' || in_array($type, ['login', 'heartbeat'], true)) {
            return $responses;
        }

        $ident = $this->replyIdent($decoded['ident'] ?? null);
        if ($type === 'upGetDevBindStatus') {
            return [$this->downlink($session, 'dnDevBindStatus', [
                'status' => (int)($state['bindStatus'] ?? 0),
            ], $ident, $timestamp)];
        }
        if ($type === 'upGetDevConfig') {
            return $this->configurationSyncResponses($session, $state['configurations'] ?? [], $ident, $timestamp);
        }
        if ($type === 'upSleepFind') {
            $sleep = is_array($state['sleep'] ?? null) ? $state['sleep'] : [];
            return [$this->downlink($session, 'dnUpSleep', [
                'upDayStr' => (string)($decoded['data']['upDayStr'] ?? gmdate('Y-m-d')),
                'value' => $this->sleepSummary($sleep),
            ], $ident, $timestamp)];
        }
        if ($type === 'upWeather' && is_array($state['weather'] ?? null)) {
            return [$this->downlink($session, 'dnWeather', $this->weatherPayload($state['weather']), $ident, $timestamp)];
        }

        $responses[] = new WatchResponse($this->encodeOutgoing([
            'type' => $type,
            'ident' => $ident,
            'ref' => 's:reply',
            'imei' => $session->imei,
            'data' => [
                'type' => $type,
                'imei' => $session->imei,
                'timestamp' => $timestamp,
            ],
            'timestamp' => $timestamp,
        ]), true);

        return $responses;
    }

    private function state(DeviceSession $session): array
    {
        if (!is_callable($this->stateProvider)) {
            return [
                'bindStatus' => $session->licenseId !== '0' && strtolower($session->company) !== 'null' ? 1 : 0,
                'configurations' => [],
            ];
        }

        $state = ($this->stateProvider)($session);
        return is_array($state) ? $state : [];
    }

    private function downlink(
        DeviceSession $session,
        string $type,
        array $data,
        int $ident,
        int $timestamp
    ): WatchResponse {
        return new WatchResponse($this->encodeOutgoing([
            'type' => $type,
            'ident' => $ident,
            'ref' => 's:down',
            'imei' => $session->imei,
            'data' => array_replace([
                'type' => $type,
                'imei' => $session->imei,
                'timestamp' => $timestamp,
            ], $data),
            'timestamp' => $timestamp,
        ]), true);
    }

    /**
     * @return list<WatchResponse>
     */
    private function configurationSyncResponses(
        DeviceSession $session,
        mixed $stored,
        int $ident,
        int $timestamp
    ): array {
        $stored = is_array($stored) ? $stored : [];
        $locationInterval = 0;
        $measurementConfigs = [];
        $deviceConfigs = [];

        foreach ($stored as $entry) {
            if (!is_array($entry) || !is_array($entry['payload'] ?? null)) {
                continue;
            }
            $command = (string)($entry['command'] ?? '');
            $payload = $entry['payload'];
            if ($command === 'locationInterval') {
                $locationInterval = (int)($payload['intervalTime'] ?? 0);
                continue;
            }
            if ($command === 'deviceMeasuringFrequency') {
                if (is_array($payload['configs'] ?? null)) {
                    $measurementConfigs = array_replace_recursive($measurementConfigs, $payload['configs']);
                }
                continue;
            }
            if ($command === 'deviceConfig') {
                if (is_array($payload['configs'] ?? null)) {
                    $deviceConfigs = array_replace_recursive($deviceConfigs, $payload['configs']);
                }
            }
        }

        return [
            $this->downlink($session, 'locationInterval', ['intervalTime' => $locationInterval], $ident, $timestamp),
            $this->downlink($session, 'deviceMeasuringFrequency', ['configs' => $measurementConfigs], $ident, $timestamp),
            $this->downlink($session, 'deviceConfig', ['configs' => $deviceConfigs], $ident, $timestamp),
        ];
    }

    private function sleepSummary(array $sleep): string
    {
        $totals = ['deepSleep' => 0, 'lightSleep' => 0, 'sober' => 0];
        foreach ($sleep['segments'] ?? [] as $segment) {
            if (!is_array($segment)) {
                continue;
            }
            $type = (string)($segment['type'] ?? '');
            if ($type === 'rem') {
                $type = 'lightSleep';
            }
            if (array_key_exists($type, $totals)) {
                $totals[$type] += (int)($segment['durationMinutes'] ?? 0);
            }
        }
        $total = array_sum($totals);
        return implode('/', [$total, $totals['deepSleep'], $totals['lightSleep'], $totals['sober']]);
    }

    private function weatherPayload(array $weather): array
    {
        return [
            'iIsCDMA' => (string)($weather['iIsCDMA'] ?? '0'),
            'weather' => (string)($weather['weather'] ?? $weather['summary'] ?? ''),
            'weatherType' => (int)($weather['weatherType'] ?? 0),
            'province' => (string)($weather['province'] ?? ''),
            'city' => (string)($weather['city'] ?? ''),
            'adcode' => (string)($weather['adcode'] ?? ''),
            'temperature' => (string)($weather['temperature'] ?? $weather['temperatureCelsius'] ?? ''),
            'winddirection' => (string)($weather['winddirection'] ?? ''),
            'windpower' => (string)($weather['windpower'] ?? ''),
            'humidity' => (string)($weather['humidity'] ?? $weather['humidityPercent'] ?? ''),
            'daytemp' => (string)($weather['daytemp'] ?? $weather['highCelsius'] ?? ''),
            'nighttemp' => (string)($weather['nighttemp'] ?? $weather['lowCelsius'] ?? ''),
            'reporttime' => (string)($weather['reporttime'] ?? gmdate('Y-m-d H:i:s')),
        ];
    }

    public function commandMetadata(string $bytes): ?array
    {
        $decoded = $this->decodeIncoming($bytes);
        if (!is_array($decoded)) {
            return null;
        }

        $metadata = array_filter([
            'nativeType' => (string)($decoded['type'] ?? ''),
            'protocol' => $this->protocol(),
            'ident' => $decoded['ident'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return $metadata !== [] ? $metadata : null;
    }

    private function replyIdent(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && is_numeric($value) && (int)$value > 0) {
            return (int)$value;
        }

        return random_int(100000, 999999);
    }
}
