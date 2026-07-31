<?php

namespace Hub\Location;

use React\Promise\PromiseInterface;

use function React\Promise\resolve;
use function React\Promise\reject;

final class BeaconDbTelemetryEnricher implements LocationTelemetryEnricherContract
{
    private \Closure $resolver;

    /** @var array<string, array{expiresAt: float, coordinates: null|array<string, float|bool>}> */
    private array $cache = [];

    /** @var array<string, PromiseInterface<array{httpStatus: int, body: array<string, mixed>}>> */
    private array $pending = [];

    public function __construct(
        private readonly BeaconDbRequestBuilder $requestBuilder,
        callable $resolver,
        private readonly float $maxAccuracyMeters = 5000.0,
        private readonly int $cacheTtlSeconds = 300,
        private readonly int $failureCacheTtlSeconds = 60,
    ) {
        $this->resolver = \Closure::fromCallable($resolver);
    }

    public function enrich(array $telemetry): PromiseInterface
    {
        if (($telemetry['type'] ?? null) !== 'location') {
            return resolve($telemetry);
        }

        $data = isset($telemetry['data']) && is_array($telemetry['data']) ? $telemetry['data'] : [];
        if ($this->hasTrustedGpsCoordinates($data)) {
            return resolve($telemetry);
        }
        $unresolvedTelemetry = $this->withoutUntrustedCoordinates($telemetry);

        $request = $this->requestBuilder->build($telemetry);
        if ($request === null) {
            return resolve($unresolvedTelemetry);
        }

        $key = $this->cacheKey($request);
        $cached = $this->cache[$key] ?? null;
        if ($cached !== null && $cached['expiresAt'] >= microtime(true)) {
            return resolve($cached['coordinates'] === null
                ? $unresolvedTelemetry
                : $this->withCoordinates($telemetry, $cached['coordinates']));
        }
        unset($this->cache[$key]);

        try {
            $promise = $this->pending[$key] ?? ($this->resolver)($request);
        } catch (\Throwable $error) {
            return reject($error);
        }
        if (!$promise instanceof PromiseInterface) {
            return reject(new \RuntimeException('BeaconDB resolver must return a promise'));
        }
        $this->pending[$key] = $promise;

        return $promise->then(
            function (array $response) use ($telemetry, $key): array {
                unset($this->pending[$key]);
                $coordinates = $this->coordinates($response);
                if ($this->cacheTtlSeconds > 0) {
                    $this->cache[$key] = [
                        'expiresAt' => microtime(true) + $this->cacheTtlSeconds,
                        'coordinates' => $coordinates,
                    ];
                }

                return $this->withCoordinates($telemetry, $coordinates);
            }
        )->then(
            null,
            function (\Throwable $error) use ($key, $unresolvedTelemetry): array {
                unset($this->pending[$key]);
                if ($this->failureCacheTtlSeconds > 0) {
                    $this->cache[$key] = [
                        'expiresAt' => microtime(true) + $this->failureCacheTtlSeconds,
                        'coordinates' => null,
                    ];
                }
                $imei = (string)($unresolvedTelemetry['device']['id'] ?? 'unknown');
                \Hub\Log\Logger::channel('hub')->warning(
                    "Location resolution failed IMEI={$imei}: {$error->getMessage()}"
                );
                return $unresolvedTelemetry;
            }
        );
    }

    private function hasTrustedGpsCoordinates(array $data): bool
    {
        return strtolower(trim((string)($data['source'] ?? ''))) === 'gps'
            && ($data['gpsValid'] ?? true) !== false
            && isset($data['lat'], $data['lon'])
            && is_numeric($data['lat'])
            && is_numeric($data['lon'])
            && (float)$data['lat'] >= -90.0
            && (float)$data['lat'] <= 90.0
            && (float)$data['lon'] >= -180.0
            && (float)$data['lon'] <= 180.0
            && !((float)$data['lat'] === 0.0 && (float)$data['lon'] === 0.0);
    }

    private function cacheKey(array $request): string
    {
        $identity = $request;
        if (isset($identity['cellTowers']) && is_array($identity['cellTowers'])) {
            foreach ($identity['cellTowers'] as &$cell) {
                unset($cell['signalStrength'], $cell['timingAdvance']);
            }
            unset($cell);
        }
        if (isset($identity['wifiAccessPoints']) && is_array($identity['wifiAccessPoints'])) {
            foreach ($identity['wifiAccessPoints'] as &$wifi) {
                unset($wifi['signalStrength'], $wifi['ssid'], $wifi['channel'], $wifi['frequency']);
            }
            unset($wifi);
        }

        if (isset($identity['cellTowers']) && is_array($identity['cellTowers'])) {
            usort($identity['cellTowers'], static fn (array $left, array $right): int => json_encode($left) <=> json_encode($right));
        }
        if (isset($identity['wifiAccessPoints']) && is_array($identity['wifiAccessPoints'])) {
            usort($identity['wifiAccessPoints'], static fn (array $left, array $right): int => json_encode($left) <=> json_encode($right));
        }

        return hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function withoutUntrustedCoordinates(array $telemetry): array
    {
        $data = isset($telemetry['data']) && is_array($telemetry['data']) ? $telemetry['data'] : [];
        unset($data['lat'], $data['lon'], $data['accuracyMeters']);
        $data['hasCoordinates'] = false;
        $telemetry['data'] = $data;

        return $telemetry;
    }

    /** @return array<string, float|bool> */
    private function coordinates(array $response): array
    {
        $status = (int)($response['httpStatus'] ?? 0);
        $body = isset($response['body']) && is_array($response['body']) ? $response['body'] : [];
        $location = isset($body['location']) && is_array($body['location']) ? $body['location'] : [];
        $lat = $location['lat'] ?? null;
        $lon = $location['lng'] ?? $location['lon'] ?? null;
        $accuracy = $body['accuracy'] ?? null;

        if ($status < 200 || $status >= 300 || !is_numeric($lat) || !is_numeric($lon)) {
            throw new \RuntimeException("BeaconDB did not resolve the location (HTTP {$status})");
        }

        $lat = (float)$lat;
        $lon = (float)$lon;
        if ($lat < -90.0 || $lat > 90.0 || $lon < -180.0 || $lon > 180.0 || ($lat === 0.0 && $lon === 0.0)) {
            throw new \RuntimeException('BeaconDB returned invalid coordinates');
        }
        if ($accuracy !== null && (!is_numeric($accuracy) || (float)$accuracy < 0.0 || (float)$accuracy > $this->maxAccuracyMeters)) {
            throw new \RuntimeException('BeaconDB returned an unacceptable accuracy');
        }

        $coordinates = [
            'hasCoordinates' => true,
            'lat' => $lat,
            'lon' => $lon,
        ];
        if ($accuracy !== null) {
            $coordinates['accuracyMeters'] = (float)$accuracy;
        }

        return $coordinates;
    }

    /** @param array<string, float|bool> $coordinates */
    private function withCoordinates(array $telemetry, array $coordinates): array
    {
        $data = isset($telemetry['data']) && is_array($telemetry['data']) ? $telemetry['data'] : [];
        $telemetry['data'] = array_merge($data, $coordinates);

        return $telemetry;
    }
}
