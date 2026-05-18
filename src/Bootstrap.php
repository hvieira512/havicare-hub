<?php

namespace App;

use App\Database\Database;
use App\Log\Logger;
use App\Redis\Client as RedisClient;
use App\Registry\DeviceCapabilities;

class Bootstrap
{
    public static function config(): array
    {
        return Config::load()->all();
    }

    public static function database(?array $dbConfig, string $channel = 'db'): ?\PDO
    {
        if ($dbConfig === null || ($dbConfig['host'] ?? '') === '' || ($dbConfig['name'] ?? '') === '') {
            return null;
        }

        try {
            $pdo = Database::connect($dbConfig)->pdo();
            Logger::channel($channel)->info("Connected to MySQL at {$dbConfig['host']}:{$dbConfig['port']}/{$dbConfig['name']}");
            return $pdo;
        } catch (\PDOException $e) {
            Logger::channel($channel)->warning('MySQL unavailable (' . $e->getMessage() . ')');
            return null;
        }
    }

    public static function requireDatabase(?array $dbConfig, string $channel = 'db'): \PDO
    {
        $pdo = self::database($dbConfig, $channel);
        if ($pdo === null) {
            Logger::channel($channel)->error('MySQL is required but unavailable');
            exit(1);
        }
        return $pdo;
    }

    public static function redis(array $redisConfig): ?RedisClient
    {
        $redisHost = getenv('REDIS_HOST') ?: ($redisConfig['host'] ?? '');
        if ($redisHost === '') {
            return null;
        }

        $redis = new RedisClient($redisConfig);
        if (!$redis->isAvailable()) {
            Logger::channel('redis')->warning('Redis is unavailable');
            return null;
        }

        return $redis;
    }

    public static function requireRedis(array $redisConfig): RedisClient
    {
        $redis = self::redis($redisConfig);
        if ($redis === null) {
            Logger::channel('redis')->error('Redis is required but unavailable');
            exit(1);
        }
        return $redis;
    }

    public static function setupDeviceCapabilities(?\PDO $pdo): void
    {
        DeviceCapabilities::setDatabasePdo($pdo);
        DeviceCapabilities::setCacheTtl((int)(getenv('MODEL_CACHE_TTL_SECONDS') ?: 5));
    }
}
