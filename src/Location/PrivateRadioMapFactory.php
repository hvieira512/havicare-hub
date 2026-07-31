<?php

declare(strict_types=1);

namespace Hub\Location;

use PDO;

final class PrivateRadioMapFactory
{
    public static function create(PDO $pdo, array $config, ?BeaconDbRequestBuilder $requestBuilder = null): PrivateRadioMap
    {
        return new PrivateRadioMap(
            new PdoPrivateRadioMapStore(
                $pdo,
                (int)($config['radio_map_cache_ttl_seconds'] ?? 60),
            ),
            $requestBuilder ?? new BeaconDbRequestBuilder(),
            trim((string)($config['radio_map_hash_key'] ?? '')),
            (int)($config['radio_map_minimum_matches'] ?? 2),
            (float)($config['radio_map_maximum_learning_accuracy_meters'] ?? 100.0),
            (float)($config['radio_map_default_gps_accuracy_meters'] ?? 50.0),
            (int)($config['radio_map_minimum_satellites'] ?? 4),
            (float)($config['radio_map_maximum_observation_distance_meters'] ?? 250.0),
            (float)($config['radio_map_cluster_radius_meters'] ?? 150.0),
            (float)($config['max_accuracy_meters'] ?? 500.0),
        );
    }
}
