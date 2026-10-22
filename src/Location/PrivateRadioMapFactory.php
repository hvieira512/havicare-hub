<?php

declare(strict_types=1);

namespace Hub\Location;

use PDO;

final class PrivateRadioMapFactory
{
    /** @param array<string, mixed> $config */
    public static function create(PDO $pdo, array $config, ?BeaconDbRequestBuilder $requestBuilder = null): PrivateRadioMap
    {
        return new PrivateRadioMap(
            new PdoPrivateRadioMapStore($pdo),
            $requestBuilder ?? new BeaconDbRequestBuilder(),
            trim((string)($config['radio_map_hash_key'] ?? '')),
        );
    }
}
