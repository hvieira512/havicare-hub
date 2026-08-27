<?php

declare(strict_types=1);

namespace Hub\Location;

use Hub\Log\Logger;
use PDO;
use Predis\ClientInterface;
use React\Http\Browser;

/**
 * Monta o pipeline de resolução de localização: um cliente da BeaconDB atrás de um disjuntor
 * e de um limitador de concorrência, à frente dele uma cache em camadas e, opcionalmente, o
 * mapa de rádio privado.
 */
final class LocationEnricherFactory
{
    /**
     * @param array<string, mixed> $config the `location_resolution` section of the hub config
     *
     * @return LocationTelemetryEnricherContract|null null when resolution is disabled
     */
    public static function create(
        array $config,
        PDO $pdo,
        ClientInterface $redis,
        ?Browser $browser = null,
    ): ?LocationTelemetryEnricherContract {
        if (!(bool)($config['enabled'] ?? false)) {
            return null;
        }

        $provider = new ConcurrentLocationProvider(
            new CircuitBreakingLocationProvider(
                new BeaconDbAsyncClient(
                    $browser ?? new Browser(),
                    (string)$config['endpoint'],
                    (string)$config['user_agent'],
                    (float)$config['timeout_seconds'],
                ),
                new RedisProviderCircuitStateStore($redis),
                (int)$config['circuit_failure_threshold'],
                (int)$config['circuit_open_seconds'],
                (int)$config['rate_limit_open_seconds'],
            ),
            (int)$config['max_concurrency'],
            (int)$config['max_queue'],
        );

        $requestBuilder = new BeaconDbRequestBuilder();
        $enricher = new BeaconDbTelemetryEnricher(
            $requestBuilder,
            $provider,
            (float)$config['max_accuracy_meters'],
            (int)$config['cache_ttl_seconds'],
            (int)$config['failure_cache_ttl_seconds'],
            new TieredLocationResolutionCache(
                new ArrayLocationResolutionCache(),
                new RedisLocationResolutionCache($redis),
            ),
        );

        if (!(bool)($config['radio_map_enabled'] ?? false)) {
            return $enricher;
        }

        // Sem chave de hash o mapa de rádio não consegue anonimizar os pontos de acesso, e
        // por isso recorre-se à resolução directa pela BeaconDB em vez de não arrancar.
        if (trim((string)($config['radio_map_hash_key'] ?? '')) === '') {
            Logger::channel('hub')->error('Private radio map disabled because RADIO_MAP_HASH_KEY is empty');

            return $enricher;
        }

        return new PrivateRadioMapTelemetryEnricher(
            PrivateRadioMapFactory::create($pdo, $config, $requestBuilder),
            $enricher,
        );
    }
}
