<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/** O gateway deixa de publicar `location`: sai dos modelos de gateway. */
final class GatewayLocationRemoved implements Migration
{
    public function version(): string
    {
        return '2026_10_26_gateway_location_removed';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec("DELETE FROM model_capabilities WHERE device_type = 'gateway' AND capability_key = 'location'");
    }
}
