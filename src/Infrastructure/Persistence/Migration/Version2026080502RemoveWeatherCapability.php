<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

final class Version2026080502RemoveWeatherCapability implements Migration
{
    public function version(): string
    {
        return '2026080502_remove_weather_capability';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec("
            DELETE FROM device_configuration_changes
            WHERE config_key IN ('weather_data', 'weatherData')
        ");
        $pdo->exec("
            DELETE FROM device_configurations
            WHERE config_key IN ('weather_data', 'weatherData')
               OR native_key IN ('weather_data', 'weatherData')
               OR command = 'dnWeather'
        ");
        $pdo->exec("
            DELETE mc
            FROM model_capabilities mc
            JOIN capabilities c ON c.id = mc.capability_id
            WHERE c.device_type = 'watch' AND c.capability_key = 'weather_data'
        ");
        $pdo->exec("
            DELETE FROM capabilities
            WHERE device_type = 'watch' AND capability_key = 'weather_data'
        ");
    }
}
