<?php

namespace App\Domain\Normalizer;

final class VivistarEventNormalizer implements ProtocolEventNormalizerInterface
{
    public function protocol(): string
    {
        return 'vivistar-iw';
    }

    public function canNormalize(?string $feature, ?string $nativeType, array $payload, ?string $protocol): bool
    {
        if ($feature !== 'location') {
            return false;
        }

        if (!is_array($payload['fields'] ?? null)) {
            return false;
        }

        if ($protocol === $this->protocol()) {
            return true;
        }

        if ($protocol !== null && $protocol !== '') {
            return false;
        }

        return is_string($nativeType) && preg_match('/^AP[A-Z0-9]{2}$/', $nativeType) === 1;
    }

    public function normalize(?string $feature, ?string $nativeType, array $payload, array $normalized): array
    {
        if ($feature !== 'location') {
            return $normalized;
        }

        $vivistar = $this->normalizeLocation($payload);
        if ($vivistar === []) {
            return $normalized;
        }

        return array_merge($normalized, $vivistar);
    }

    private function normalizeLocation(array $payload): array
    {
        $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];
        if ($fields === []) {
            return [];
        }

        $normalized = [];

        $rawHead = (string)($fields[0] ?? '');
        if (str_contains($rawHead, '|')) {
            $normalized['source'] = 'lbs_wifi';
        } elseif (str_starts_with($rawHead, 'zh_')) {
            $normalized['source'] = 'lbs_wifi';
        } else {
            $normalized['source'] = 'gps_lbs';
        }

        $gpsExtract = $this->parseGpsTuple($rawHead);
        if ($gpsExtract !== []) {
            $normalized = array_merge($normalized, $gpsExtract);
        }

        $isAp02Style = str_starts_with(strtolower($rawHead), 'zh_');
        $mcc = $isAp02Style
            ? $this->toNullableInt($fields[3] ?? null)
            : $this->toNullableInt($fields[1] ?? null);
        $mnc = $isAp02Style
            ? $this->toNullableInt($fields[4] ?? null)
            : $this->toNullableInt($fields[2] ?? null);
        $cellComposite = $isAp02Style
            ? (string)($fields[5] ?? '')
            : (string)($fields[4] ?? '');
        if (str_contains($cellComposite, '|')) {
            $parts = explode('|', $cellComposite);
            $normalized['lac'] = $this->toNullableInt($parts[0] ?? null);
            $normalized['cellId'] = $this->toNullableInt($parts[1] ?? null);
            if (($parts[2] ?? '') !== '') {
                $normalized['baseStationSignal'] = $this->toNullableInt($parts[2]);
            }
        } else {
            if ($isAp02Style) {
                $normalized['lac'] = $this->toNullableInt($fields[5] ?? null);
                $normalized['cellId'] = $this->toNullableInt($fields[6] ?? null);
            } else {
                $normalized['lac'] = $this->toNullableInt($fields[3] ?? null);
                $normalized['cellId'] = $this->toNullableInt($fields[4] ?? null);
            }
        }

        if ($mcc !== null) {
            $normalized['mcc'] = $mcc;
        }
        if ($mnc !== null) {
            $normalized['mnc'] = $mnc;
        }

        $wifiCount = $this->toNullableInt($fields[6] ?? null);
        if ($wifiCount !== null) {
            $normalized['wifiCount'] = $wifiCount;
        }

        $wifiScan = (string)($fields[7] ?? $fields[5] ?? '');
        $wifiAps = $this->parseWifiAccessPoints($wifiScan);
        if ($wifiAps !== []) {
            $normalized['wifiAccessPoints'] = $wifiAps;
            $normalized['wifiCount'] = count($wifiAps);
        }

        $baseStationCount = $this->toNullableInt($fields[2] ?? null);
        if ($baseStationCount !== null) {
            $normalized['baseStationCount'] = $baseStationCount;
        }

        return array_filter(
            $normalized,
            static fn($value) => $value !== null && $value !== '' && $value !== []
        );
    }

    private function parseGpsTuple(string $value): array
    {
        if ($value === '') {
            return [];
        }

        if (preg_match('/\d{6}[AV](\d{4}\.\d+)([NS])(\d{5}\.\d+)([EW])/', $value, $m) !== 1) {
            return [];
        }

        $lat = $this->ddmmToDecimal($m[1], $m[2], true);
        $lon = $this->ddmmToDecimal($m[3], $m[4], false);

        return array_filter([
            'latitude' => $lat,
            'longitude' => $lon,
        ], static fn($v) => $v !== null);
    }

    private function ddmmToDecimal(string $value, string $hemi, bool $isLat): ?float
    {
        $degreesDigits = $isLat ? 2 : 3;
        if (strlen($value) <= $degreesDigits || !is_numeric($value)) {
            return null;
        }

        $degrees = (float)substr($value, 0, $degreesDigits);
        $minutes = (float)substr($value, $degreesDigits);
        $decimal = $degrees + ($minutes / 60.0);
        if (in_array($hemi, ['S', 'W'], true)) {
            $decimal *= -1.0;
        }

        return round($decimal, 6);
    }

    private function parseWifiAccessPoints(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $entries = preg_split('/&/', $raw) ?: [];
        $wifi = [];
        foreach ($entries as $entry) {
            $parts = explode('|', trim($entry));
            if (count($parts) < 3) {
                continue;
            }
            $mac = trim((string)$parts[1]);
            if ($mac === '') {
                continue;
            }
            $wifi[] = array_filter([
                'mac' => strtolower($mac),
                'rssi' => $this->toNullableInt($parts[2] ?? null),
            ], static fn($v) => $v !== null && $v !== '');
        }

        return $wifi;
    }

    private function toNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        return (int)$value;
    }
}
