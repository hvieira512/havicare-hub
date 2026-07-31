<?php

declare(strict_types=1);

namespace Hub\Location;

use React\Promise\PromiseInterface;

use function React\Promise\resolve;

final class PrivateRadioMapTelemetryEnricher implements LocationTelemetryEnricherContract
{
    public function __construct(
        private readonly PrivateRadioMap $radioMap,
        private readonly LocationTelemetryEnricherContract $publicFallback,
    ) {
    }

    public function enrich(array $telemetry): PromiseInterface
    {
        if (($telemetry['type'] ?? null) !== 'location') {
            return $this->publicFallback->enrich($telemetry);
        }

        try {
            $this->radioMap->learnFromTelemetry($telemetry);
            $data = isset($telemetry['data']) && is_array($telemetry['data']) ? $telemetry['data'] : [];
            if (strtolower(trim((string)($data['source'] ?? ''))) === 'gps'
                && ($data['gpsValid'] ?? true) !== false) {
                return $this->publicFallback->enrich($telemetry);
            }
            $coordinates = $this->radioMap->resolveTelemetry($telemetry);
            if ($coordinates !== null) {
                $telemetry['data'] = array_merge($data, $coordinates);
                return resolve($telemetry);
            }
        } catch (\Throwable $error) {
            \Hub\Log\Logger::channel('hub')->warning(
                "Private radio map unavailable: {$error->getMessage()}"
            );
        }

        return $this->publicFallback->enrich($telemetry);
    }
}
