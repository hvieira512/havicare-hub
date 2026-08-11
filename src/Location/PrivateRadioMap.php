<?php

declare(strict_types=1);

namespace Hub\Location;

final class PrivateRadioMap
{
    public function __construct(
        private readonly PrivateRadioMapStoreContract $store,
        private readonly BeaconDbRequestBuilder $requestBuilder,
        private readonly string $hashKey,
        private readonly int $minimumMatches = 2,
        private readonly float $maximumLearningAccuracyMeters = 100.0,
        private readonly float $defaultGpsAccuracyMeters = 50.0,
        private readonly int $minimumSatellites = 4,
        private readonly float $maximumObservationDistanceMeters = 250.0,
        private readonly float $clusterRadiusMeters = 150.0,
        private readonly float $maximumResolutionAccuracyMeters = 500.0,
    ) {
    }

    public function learnFromTelemetry(array $telemetry): int
    {
        $data = isset($telemetry['data']) && is_array($telemetry['data']) ? $telemetry['data'] : [];
        $fix = $this->trustedGpsFix($data);
        if ($fix === null) {
            return 0;
        }

        $request = $this->requestBuilder->build($telemetry);
        $wifi = isset($request['wifiAccessPoints']) && is_array($request['wifiAccessPoints'])
            ? $request['wifiAccessPoints']
            : [];
        if ($wifi === []) {
            return 0;
        }

        $hashes = $this->hashes($wifi);
        $existing = $this->store->findMany(array_values($hashes));
        $now = gmdate('Y-m-d H:i:s');
        $learned = 0;
        $updates = [];

        foreach ($hashes as $hash) {
            $current = $existing[$hash] ?? null;
            if (is_array($current) && ($current['source'] ?? '') === 'manual') {
                continue;
            }

            if (is_array($current)) {
                $distance = $this->distanceMeters(
                    (float)$current['lat'],
                    (float)$current['lon'],
                    $fix['lat'],
                    $fix['lon'],
                );
                if (($current['conflicted'] ?? false) || $distance > $this->maximumObservationDistanceMeters) {
                    $current['conflicted'] = true;
                    $current['observationCount'] = max(1, (int)($current['observationCount'] ?? 1)) + 1;
                    $current['lastSeenAt'] = $now;
                    $updates[$hash] = $current;
                    continue;
                }

                $count = max(1, (int)($current['observationCount'] ?? 1));
                $newCount = min(1000, $count + 1);
                $weight = $count >= 1000 ? 1.0 / 1000.0 : 1.0 / $newCount;
                $lat = (float)$current['lat'] + ($fix['lat'] - (float)$current['lat']) * $weight;
                $lon = (float)$current['lon'] + ($fix['lon'] - (float)$current['lon']) * $weight;
                $oldCenterShift = $this->distanceMeters((float)$current['lat'], (float)$current['lon'], $lat, $lon);
                $newFixDistance = $this->distanceMeters($lat, $lon, $fix['lat'], $fix['lon']);
                $boundedAccuracy = max(
                    (float)($current['accuracyMeters'] ?? $fix['accuracyMeters']) + $oldCenterShift,
                    $fix['accuracyMeters'] + $newFixDistance,
                );
                $entry = [
                    'lat' => $lat,
                    'lon' => $lon,
                    'accuracyMeters' => $boundedAccuracy,
                    'observationCount' => $newCount,
                    'source' => 'learned',
                    'conflicted' => $boundedAccuracy > $this->maximumObservationDistanceMeters,
                    'firstSeenAt' => (string)($current['firstSeenAt'] ?? $now),
                    'lastSeenAt' => $now,
                ];
            } else {
                $entry = [
                    'lat' => $fix['lat'],
                    'lon' => $fix['lon'],
                    'accuracyMeters' => $fix['accuracyMeters'],
                    'observationCount' => 1,
                    'source' => 'learned',
                    'conflicted' => false,
                    'firstSeenAt' => $now,
                    'lastSeenAt' => $now,
                ];
            }

            $updates[$hash] = $entry;
            $learned++;
        }

        $this->store->saveMany($updates);

        return $learned;
    }

    /** @param list<string> $bssids */
    public function seed(array $bssids, float $lat, float $lon, float $accuracyMeters): int
    {
        if (!$this->validCoordinates($lat, $lon)) {
            throw new \InvalidArgumentException('Invalid seed coordinates');
        }
        if ($accuracyMeters <= 0.0 || $accuracyMeters > $this->maximumResolutionAccuracyMeters) {
            throw new \InvalidArgumentException('Seed accuracy is outside the trusted range');
        }

        $request = $this->requestBuilder->build(['wifiAccessPoints' => array_map(
            static fn (string $bssid): array => ['mac' => $bssid],
            $bssids,
        )]);
        $wifi = isset($request['wifiAccessPoints']) && is_array($request['wifiAccessPoints'])
            ? $request['wifiAccessPoints']
            : [];
        if (count($wifi) < $this->minimumMatches) {
            throw new \InvalidArgumentException("At least {$this->minimumMatches} valid BSSIDs are required");
        }

        $now = gmdate('Y-m-d H:i:s');
        $entries = [];
        foreach ($this->hashes($wifi) as $hash) {
            $entries[$hash] = [
                'lat' => $lat,
                'lon' => $lon,
                'accuracyMeters' => $accuracyMeters,
                'observationCount' => 1,
                'source' => 'manual',
                'conflicted' => false,
                'firstSeenAt' => $now,
                'lastSeenAt' => $now,
            ];
        }
        $this->store->saveMany($entries);

        return count($wifi);
    }

    /** @return array{hasCoordinates: true, lat: float, lon: float, accuracyMeters: float}|null */
    public function resolveTelemetry(array $telemetry): ?array
    {
        $request = $this->requestBuilder->build($telemetry);
        if ($request === null) {
            return null;
        }
        return $this->resolveRequest($request);
    }

    /** @return array{hasCoordinates: true, lat: float, lon: float, accuracyMeters: float}|null */
    public function resolveRequest(array $request): ?array
    {
        $wifi = isset($request['wifiAccessPoints']) && is_array($request['wifiAccessPoints'])
            ? $request['wifiAccessPoints']
            : [];
        if (count($wifi) < $this->minimumMatches) {
            return null;
        }

        $hashes = $this->hashes($wifi);
        $entries = $this->store->findMany(array_values($hashes));
        $candidates = array_values(array_filter(
            $entries,
            fn (mixed $entry): bool => is_array($entry)
                && !($entry['conflicted'] ?? false)
                && $this->validCoordinates((float)($entry['lat'] ?? 0.0), (float)($entry['lon'] ?? 0.0))
                && (float)($entry['accuracyMeters'] ?? 0.0) > 0.0,
        ));
        if (count($candidates) < $this->minimumMatches) {
            return null;
        }

        $cluster = $this->largestCluster($candidates);
        if (count($cluster) < $this->minimumMatches) {
            return null;
        }

        $weightSum = 0.0;
        $lat = 0.0;
        $lon = 0.0;
        foreach ($cluster as $entry) {
            $accuracy = max(10.0, (float)$entry['accuracyMeters']);
            $weight = 1.0 / ($accuracy * $accuracy);
            $weightSum += $weight;
            $lat += (float)$entry['lat'] * $weight;
            $lon += (float)$entry['lon'] * $weight;
        }
        if ($weightSum <= 0.0) {
            return null;
        }
        $lat /= $weightSum;
        $lon /= $weightSum;

        $spread = 0.0;
        $inputAccuracy = 0.0;
        foreach ($cluster as $entry) {
            $spread = max($spread, $this->distanceMeters($lat, $lon, (float)$entry['lat'], (float)$entry['lon']));
            $inputAccuracy = max($inputAccuracy, (float)$entry['accuracyMeters']);
        }
        $accuracy = max(25.0, $inputAccuracy + $spread);
        if ($accuracy > $this->maximumResolutionAccuracyMeters) {
            return null;
        }

        return [
            'hasCoordinates' => true,
            'lat' => round($lat, 7),
            'lon' => round($lon, 7),
            'accuracyMeters' => round($accuracy, 2),
        ];
    }

    /** @param list<array<string, mixed>> $candidates @return list<array<string, mixed>> */
    private function largestCluster(array $candidates): array
    {
        $best = [];
        foreach ($candidates as $anchor) {
            $cluster = [];
            foreach ($candidates as $candidate) {
                if ($this->distanceMeters(
                    (float)$anchor['lat'],
                    (float)$anchor['lon'],
                    (float)$candidate['lat'],
                    (float)$candidate['lon'],
                ) <= $this->clusterRadiusMeters) {
                    $cluster[] = $candidate;
                }
            }
            if (count($cluster) > count($best)) {
                $best = $cluster;
            }
        }
        return $best;
    }

    /** @param list<array<string, mixed>> $wifi @return array<string, string> */
    private function hashes(array $wifi): array
    {
        $hashes = [];
        foreach ($wifi as $point) {
            if (!is_array($point)) {
                continue;
            }
            $mac = strtolower(trim((string)($point['macAddress'] ?? '')));
            if ($mac !== '') {
                $hashes[$mac] = hash_hmac('sha256', $mac, $this->hashKey);
            }
        }
        return $hashes;
    }

    /** @return array{lat: float, lon: float, accuracyMeters: float}|null */
    private function trustedGpsFix(array $data): ?array
    {
        if (strtolower(trim((string)($data['source'] ?? ''))) !== 'gps'
            || ($data['gpsValid'] ?? true) === false
            || !is_numeric($data['lat'] ?? null)
            || !is_numeric($data['lon'] ?? null)) {
            return null;
        }
        $lat = (float)$data['lat'];
        $lon = (float)$data['lon'];
        if (!$this->validCoordinates($lat, $lon)) {
            return null;
        }

        $accuracy = $data['accuracyMeters'] ?? null;
        if (is_numeric($accuracy) && (float)$accuracy > 0.0) {
            $accuracy = (float)$accuracy;
            if ($accuracy > $this->maximumLearningAccuracyMeters) {
                return null;
            }
        } else {
            $satellites = is_numeric($data['satelliteCount'] ?? null) ? (int)$data['satelliteCount'] : 0;
            if ($satellites < $this->minimumSatellites) {
                return null;
            }
            $accuracy = $this->defaultGpsAccuracyMeters;
        }

        return ['lat' => $lat, 'lon' => $lon, 'accuracyMeters' => $accuracy];
    }

    private function validCoordinates(float $lat, float $lon): bool
    {
        return $lat >= -90.0 && $lat <= 90.0
            && $lon >= -180.0 && $lon <= 180.0
            && !($lat === 0.0 && $lon === 0.0);
    }

    private function distanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6_371_000.0;
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;
        return $earthRadius * 2 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));
    }
}
