<?php

declare(strict_types=1);

namespace Hub\State;

/** As chaves do Redis com o estado da dashboard; o prefixo de instância (`dev:`) põe-no o cliente. */
final class RedisKeys
{
    private const PREFIX = 'hub:dashboard:';

    public static function devices(): string
    {
        return self::PREFIX . 'devices';
    }

    public static function device(string $imei): string
    {
        return self::PREFIX . "device:{$imei}";
    }

    /** `raw`, `telemetry`, `events`, `commands` ou `connections`; o `:latest` e o `:seq` penduram-se nela. */
    public static function list(string $imei, string $list): string
    {
        return self::PREFIX . "device:{$imei}:{$list}";
    }

    public static function sighting(string $imei): string
    {
        return self::PREFIX . "sighting:{$imei}";
    }

    public static function activeAlarms(string $imei): string
    {
        return self::PREFIX . "device:{$imei}:active-alarms";
    }

    public static function proximityAlarmCandidate(string $imei): string
    {
        return self::PREFIX . "proximity-alarm-candidate:{$imei}";
    }

    public static function commandRecords(string $imei): string
    {
        return self::PREFIX . "device:{$imei}:command-records";
    }

    public static function commandIndex(): string
    {
        return self::PREFIX . 'command-index';
    }

    public static function onlineDevices(): string
    {
        return self::PREFIX . 'online-devices-by-last-seen';
    }
}
