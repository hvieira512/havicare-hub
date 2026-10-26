<?php

declare(strict_types=1);

namespace Hub\Location;

use Hub\Device\LocationNormalizer;

final class BeaconDbRequestBuilder
{
    /** @var array{array<string, mixed>, array<string, mixed>|null}|null A última trama e o seu pedido. */
    private ?array $last = null;

    /**
     * Constrói um pedido de geolocalização compatível com MLS/Ichnaea a partir de um envelope
     * de telemetria de localização normalizado (ou directamente do seu objecto `data`).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public function build(array $payload): ?array
    {
        // O mapa privado e a BeaconDB pedem o mesmo pedido da mesma trama, um a seguir ao outro.
        if ($this->last !== null && $this->last[0] === $payload) {
            return $this->last[1];
        }
        $request = $this->buildRequest($payload);
        $this->last = [$payload, $request];

        return $request;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function buildRequest(array $payload): ?array
    {
        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : $payload;

        $request = [
            'considerIp' => false,
            'fallbacks' => [
                'ipf' => false,
                'lacf' => false,
            ],
        ];

        $cells = $this->cells($data);
        if ($cells !== []) {
            $request['cellTowers'] = $cells;
        }

        $wifi = $this->wifi($data);
        if (count($wifi) >= 2) {
            $request['wifiAccessPoints'] = $wifi;
        }

        return isset($request['cellTowers']) || isset($request['wifiAccessPoints']) ? $request : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array<string, int|string>>
     */
    private function cells(array $data): array
    {
        $stations = isset($data['baseStations']) && is_array($data['baseStations'])
            ? $data['baseStations']
            : [];
        $cells = [];

        foreach ($stations as $station) {
            if (!is_array($station)) {
                continue;
            }

            $radio = $this->radioType($station['radioType'] ?? $data['radioType'] ?? null);
            $mcc = $this->integer($station['mcc'] ?? $data['mcc'] ?? null);
            $mnc = $this->integer($station['mnc'] ?? $data['mnc'] ?? null);
            $lac = $this->integer($station['lac'] ?? $data['lac'] ?? null);
            $cellId = $this->integer($station['cellId'] ?? $station['ci'] ?? $data['cellId'] ?? null);

            if ($radio === null || $mcc === null || $mnc === null || $lac === null || $cellId === null) {
                continue;
            }

            $cell = [
                'radioType' => $radio,
                'mobileCountryCode' => $mcc,
                'mobileNetworkCode' => $mnc,
                'locationAreaCode' => $lac,
                'cellId' => $cellId,
            ];
            $signal = $this->negativeInteger($station['signalStrengthDbm'] ?? null);
            if ($signal !== null) {
                $cell['signalStrength'] = $signal;
            }

            $key = implode(':', [$radio, $mcc, $mnc, $lac, $cellId]);
            $cells[$key] = $cell;
        }

        return array_values($cells);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array<string, int|string>>
     */
    private function wifi(array $data): array
    {
        $points = isset($data['wifiAccessPoints']) && is_array($data['wifiAccessPoints'])
            ? $data['wifiAccessPoints']
            : [];
        $wifi = [];

        foreach ($points as $point) {
            if (!is_array($point)) {
                continue;
            }

            $ssid = trim((string)($point['ssid'] ?? ''));
            $mac = $this->mac($point['mac'] ?? $point['bssid'] ?? null);
            if (($ssid !== '' && str_ends_with(strtolower($ssid), '_nomap')) || $mac === null) {
                continue;
            }

            $entry = ['macAddress' => $mac];
            if ($ssid !== '') {
                $entry['ssid'] = $ssid;
            }
            $signal = $this->negativeInteger($point['signalStrengthDbm'] ?? null);
            if ($signal !== null) {
                $entry['signalStrength'] = $signal;
            }
            $channel = $this->integer($point['channel'] ?? null);
            if ($channel !== null && $channel > 0) {
                $entry['channel'] = $channel;
            }
            $frequency = $this->integer($point['frequencyMhz'] ?? $point['frequency'] ?? null);
            if ($frequency !== null && $frequency > 0) {
                $entry['frequency'] = $frequency;
            }

            $wifi[$mac] = $entry;
        }

        return array_values($wifi);
    }

    /** A BeaconDB só conhece estas três. */
    private function radioType(mixed $value): ?string
    {
        $radio = LocationNormalizer::normalizeRadioType($value);

        return in_array($radio, ['gsm', 'wcdma', 'lte'], true) ? $radio : null;
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        $value = trim((string)$value);
        return $value === '' || preg_match('/^-?\d+$/', $value) !== 1 ? null : (int)$value;
    }

    private function negativeInteger(mixed $value): ?int
    {
        $value = $this->integer($value);
        return $value !== null && $value < 0 ? $value : null;
    }

    private function mac(mixed $value): ?string
    {
        $hex = str_replace(':', '', LocationNormalizer::mac($value) ?? '');
        if ($hex === '' || $hex === '000000000000' || $hex === 'ffffffffffff') {
            return null;
        }

        $firstOctet = hexdec(substr($hex, 0, 2));
        if (($firstOctet & 0x03) !== 0 || str_starts_with($hex, '00005e') || str_starts_with($hex, '000000')) {
            return null;
        }

        return implode(':', str_split($hex, 2));
    }
}
