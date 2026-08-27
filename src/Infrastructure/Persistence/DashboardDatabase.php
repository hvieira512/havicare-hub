<?php

namespace Hub\Infrastructure\Persistence;

use PDO;

final class DashboardDatabase
{
    private PDO $pdo;

    public function __construct(array $config)
    {
        $driver = strtolower(trim((string)($config['driver'] ?? '')));
        if ($driver !== 'mysql') {
            throw new \InvalidArgumentException('DashboardDatabase only supports the mysql driver');
        }

        $charset = trim((string)($config['charset'] ?? 'utf8mb4')) ?: 'utf8mb4';
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string)($config['host'] ?? '127.0.0.1'),
            (int)($config['port'] ?? 3306),
            (string)($config['name'] ?? 'hitecosystem_hub'),
            $charset,
        );
        $this->pdo = new PDO($dsn, (string)($config['username'] ?? ''), (string)($config['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec("SET time_zone = '+00:00'");
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
