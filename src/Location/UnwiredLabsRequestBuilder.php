<?php

namespace Hub\Location;

final class UnwiredLabsRequestBuilder
{
    public function build(array $request, string $token): ?array
    {
        $wifi = [];
        foreach (array_slice($request['wifiAccessPoints'] ?? [], 0, 15) as $point) {
            if (!is_array($point) || trim((string)($point['macAddress'] ?? '')) === '') {
                continue;
            }
            $entry = ['bssid' => (string)$point['macAddress']];
            foreach (['signalStrength' => 'signal', 'channel' => 'channel', 'frequency' => 'frequency'] as $source => $target) {
                if (isset($point[$source]) && is_numeric($point[$source])) {
                    $entry[$target] = (int)$point[$source];
                }
            }
            $wifi[] = $entry;
        }
        if (count($wifi) < 2) {
            return null;
        }

        $cells = [];
        foreach (array_slice($request['cellTowers'] ?? [], 0, 7) as $cell) {
            if (!is_array($cell)) {
                continue;
            }
            $required = ['mobileCountryCode', 'mobileNetworkCode', 'locationAreaCode', 'cellId'];
            foreach ($required as $field) {
                if (!isset($cell[$field]) || !is_numeric($cell[$field])) {
                    continue 2;
                }
            }
            $entry = [
                'radio' => $this->radio((string)($cell['radioType'] ?? '')),
                'mcc' => (int)$cell['mobileCountryCode'],
                'mnc' => (int)$cell['mobileNetworkCode'],
                'lac' => (int)$cell['locationAreaCode'],
                'cid' => (int)$cell['cellId'],
            ];
            if (isset($cell['signalStrength']) && is_numeric($cell['signalStrength'])) {
                $entry['signal'] = (int)$cell['signalStrength'];
            }
            $cells[] = $entry;
        }

        $radio = $cells[0]['radio'] ?? 'gsm';
        $payload = [
            'token' => $token,
            'radio' => $radio,
            'wifi' => $wifi,
            'address' => 0,
            'bt' => 0,
        ];
        if ($cells !== []) {
            $payload['mcc'] = $cells[0]['mcc'];
            $payload['mnc'] = $cells[0]['mnc'];
            $payload['cells'] = $cells;
        }

        return $payload;
    }

    private function radio(string $radio): string
    {
        return match (strtolower(trim($radio))) {
            'wcdma', 'umts' => 'umts',
            'lte' => 'lte',
            default => 'gsm',
        };
    }
}
