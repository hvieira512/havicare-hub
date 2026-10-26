<?php

declare(strict_types=1);

namespace Hub\Ingress\Tcp\Supplier\Wonlex;

use Hub\Device\DeviceEventDecoder;
use Hub\Device\DeviceSession;
use Hub\Ingress\Tcp\TcpProtocol;
use Hub\Ingress\Tcp\TcpResponse;
use Hub\Protocol\Adapter\DeviceAdapterInterface;

final class WonlexTcpProtocol extends TcpProtocol
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

    /** Alguns firmwares geram um ident novo no `w:reply` e no `w:update`. */
    public function echoesReplyIdent(): bool
    {
        return false;
    }

    public function downlinkCorrelation(string $bytes): array
    {
        $decoded = $this->adapter->decodeIncoming($bytes);

        return is_array($decoded) && isset($decoded['ident'])
            ? ['ident' => $decoded['ident'], 'ref' => (string)($decoded['ref'] ?? '')]
            : [];
    }

    /**
     * @return array<int, TcpResponse>
     */
    protected function responsesForDecoded(DeviceSession $session, array $decoded): array
    {
        $type = (string)($decoded['type'] ?? '');
        $timestamp = (int) round(microtime(true) * 1000);
        $ident = $this->replyIdent($decoded['ident'] ?? null);

        if ($type === 'login') {
            return [$this->reply($session, $type, $ident, $timestamp)];
        }
        if ($type === 'heartbeat') {
            return [$this->reply($session, $type, $ident, $timestamp, ['deviceModel' => $session->model])];
        }
        if (($decoded['ref'] ?? '') !== 'w:update' || $type === '') {
            return [];
        }

        if ($type === 'upGetDevConfig') {
            return $this->configurationSyncResponses($session, $this->state($session)['configurations'] ?? [], $ident, $timestamp);
        }
        if ($type === 'upSleepFind') {
            $sleep = $this->state($session)['sleep'] ?? null;
            $sleep = is_array($sleep) ? $sleep : [];
            return [$this->downlink($session, 'dnUpSleep', [
                'upDayStr' => (string)($decoded['data']['upDayStr'] ?? gmdate('Y-m-d')),
                'value' => $this->sleepSummary($sleep),
            ], $ident, $timestamp)];
        }
        return [$this->reply($session, $type, $ident, $timestamp, publishRaw: true)];
    }

    /**
     * O `s:reply` com que o hub responde a uma trama do relógio.
     *
     * @param array<string, mixed> $data o que segue no `data` entre o `imei` e o `timestamp`
     */
    private function reply(DeviceSession $session, string $type, int $ident, int $timestamp, array $data = [], bool $publishRaw = false): TcpResponse
    {
        return new TcpResponse($this->adapter->encodeOutgoing([
            'type' => $type,
            'ident' => $ident,
            'ref' => 's:reply',
            'imei' => $session->imei,
            'data' => ['type' => $type, 'imei' => $session->imei] + $data + ['timestamp' => $timestamp],
            'timestamp' => $timestamp,
        ]), $publishRaw);
    }

    /** @return array<string, mixed> */
    private function state(DeviceSession $session): array
    {
        if (!is_callable($this->stateProvider)) {
            return ['configurations' => []];
        }

        $state = ($this->stateProvider)($session);
        return is_array($state) ? $state : [];
    }

    /** @param array<string, mixed> $data */
    private function downlink(
        DeviceSession $session,
        string $type,
        array $data,
        int $ident,
        int $timestamp
    ): TcpResponse {
        return new TcpResponse($this->adapter->encodeOutgoing([
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
     * @return list<TcpResponse>
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

    /** @param array<string, mixed> $sleep */
    private function sleepSummary(array $sleep): string
    {
        $totals = ['deepSleep' => 0, 'lightSleep' => 0, 'sober' => 0];
        foreach ($sleep['segments'] ?? [] as $segment) {
            if (!is_array($segment)) {
                continue;
            }
            $type = (string)($segment['type'] ?? '');
            $type = match ($type) {
                'deep_sleep' => 'deepSleep',
                'light_sleep' => 'lightSleep',
                'awake' => 'sober',
                default => $type,
            };
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
