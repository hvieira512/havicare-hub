<?php

namespace Hub\Location;

use React\Promise\PromiseInterface;

use function React\Promise\resolve;

final class BeaconDbTelemetryEnricher implements LocationTelemetryEnricherContract
{
    private LocationProviderContract $provider;
    private LocationResolutionCacheContract $cache;
    private LocationResponseValidator $responseValidator;

    /** @var array<string, PromiseInterface<array{httpStatus: int, body: array<string, mixed>}>> */
    private array $pending = [];

    public function __construct(
        private readonly BeaconDbRequestBuilder $requestBuilder,
        LocationProviderContract|callable $provider,
        private readonly float $maxAccuracyMeters = 500.0,
        private readonly int $cacheTtlSeconds = 86400,
        private readonly int $failureCacheTtlSeconds = 60,
        ?LocationResolutionCacheContract $cache = null,
    ) {
        $this->provider = $provider instanceof LocationProviderContract
            ? $provider
            : new CallbackLocationProvider($provider);
        $this->cache = $cache ?? new ArrayLocationResolutionCache();
        $this->responseValidator = new LocationResponseValidator($this->maxAccuracyMeters);
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
        $source = strtolower(trim((string)($data['source'] ?? '')));
        if ($request === null || $source === 'cell' || !isset($request['wifiAccessPoints'])) {
            return resolve($unresolvedTelemetry);
        }

        $key = $this->cacheKey($request);
        $cached = $this->cacheGet($key);
        if (($cached['status'] ?? null) === 'unresolved') {
            return resolve($unresolvedTelemetry);
        }
        if (($cached['status'] ?? null) === 'resolved' && is_array($cached['coordinates'] ?? null)) {
            return resolve($this->withCoordinates($telemetry, $cached['coordinates']));
        }

        try {
            $promise = $this->pending[$key] ?? $this->provider->resolve($request);
        } catch (\Throwable $error) {
            $this->cacheUnresolved($key);
            $this->logFailure($unresolvedTelemetry, $error);
            return resolve($unresolvedTelemetry);
        }
        $this->pending[$key] = $promise;

        return $promise->then(
            function (array $response) use ($telemetry, $key): array {
                unset($this->pending[$key]);
                $coordinates = $this->responseValidator->coordinates($response);
                $this->cacheResolved($key, $coordinates);

                return $this->withCoordinates($telemetry, $coordinates);
            }
        )->then(
            null,
            function (\Throwable $error) use ($key, $unresolvedTelemetry): array {
                unset($this->pending[$key]);
                $this->cacheUnresolved($key);
                $this->logFailure($unresolvedTelemetry, $error);
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

    private function cacheGet(string $key): ?array
    {
        try {
            return $this->cache->get($key);
        } catch (\Throwable $error) {
            $this->logCacheFailure('read', $error);
            return null;
        }
    }

    /** @param array<string, float|bool> $coordinates */
    private function cacheResolved(string $key, array $coordinates): void
    {
        try {
            $this->cache->putResolved($key, $coordinates, $this->cacheTtlSeconds);
        } catch (\Throwable $error) {
            $this->logCacheFailure('write', $error);
        }
    }

    private function cacheUnresolved(string $key): void
    {
        try {
            $this->cache->putUnresolved($key, $this->failureCacheTtlSeconds);
        } catch (\Throwable $error) {
            $this->logCacheFailure('write', $error);
        }
    }

    private function logFailure(array $telemetry, \Throwable $error): void
    {
        $imei = (string)($telemetry['device']['id'] ?? 'unknown');
        \Hub\Log\Logger::channel('hub')->warning(
            "Location resolution failed IMEI={$imei} provider={$this->provider->name()}: {$error->getMessage()}"
        );
    }

    private function logCacheFailure(string $operation, \Throwable $error): void
    {
        \Hub\Log\Logger::channel('hub')->warning(
            "Location resolution cache {$operation} failed: {$error->getMessage()}"
        );
    }

    private function withoutUntrustedCoordinates(array $telemetry): array
    {
        $data = isset($telemetry['data']) && is_array($telemetry['data']) ? $telemetry['data'] : [];
        unset($data['lat'], $data['lon'], $data['accuracyMeters']);
        $data['hasCoordinates'] = false;
        $telemetry['data'] = $data;

        return $telemetry;
    }

    /** @param array<string, float|bool> $coordinates */
    private function withCoordinates(array $telemetry, array $coordinates): array
    {
        $data = isset($telemetry['data']) && is_array($telemetry['data']) ? $telemetry['data'] : [];
        $telemetry['data'] = array_merge($data, $coordinates);

        return $telemetry;
    }
}
