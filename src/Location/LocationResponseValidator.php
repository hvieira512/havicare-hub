<?php

namespace Hub\Location;

final class LocationResponseValidator
{
    public function __construct(private readonly float $maxAccuracyMeters = 500.0)
    {
    }

    /** @return array{hasCoordinates: true, lat: float, lon: float, accuracyMeters: float} */
    public function coordinates(array $response): array
    {
        $status = (int)($response['httpStatus'] ?? 0);
        $body = isset($response['body']) && is_array($response['body']) ? $response['body'] : [];
        $location = isset($body['location']) && is_array($body['location']) ? $body['location'] : [];
        $lat = $location['lat'] ?? null;
        $lon = $location['lng'] ?? $location['lon'] ?? null;
        $accuracy = $body['accuracy'] ?? $location['accuracy'] ?? null;

        if ($status < 200 || $status >= 300 || !is_numeric($lat) || !is_numeric($lon)) {
            throw new \RuntimeException("Location provider did not resolve the location (HTTP {$status})");
        }

        $lat = (float)$lat;
        $lon = (float)$lon;
        if ($lat < -90.0 || $lat > 90.0 || $lon < -180.0 || $lon > 180.0 || ($lat === 0.0 && $lon === 0.0)) {
            throw new \RuntimeException('Location provider returned invalid coordinates');
        }
        if (!is_numeric($accuracy) || (float)$accuracy < 0.0 || (float)$accuracy > $this->maxAccuracyMeters) {
            throw new \RuntimeException('Location provider returned an unacceptable accuracy');
        }

        return [
            'hasCoordinates' => true,
            'lat' => $lat,
            'lon' => $lon,
            'accuracyMeters' => (float)$accuracy,
        ];
    }
}
