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
            (string)($config['name'] ?? 'havicare_hub'),
            $charset,
        );
        $username = (string)($config['username'] ?? '');
        $password = (string)($config['password'] ?? '');
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Limita a espera numa ligação morta em vez de bloquear o event loop sem fim.
            PDO::ATTR_TIMEOUT => (int)($config['timeout_seconds'] ?? 5),
        ];

        // A ligação real vive dentro do ReconnectingPdo e é refeita quando o MySQL a larga.
        $this->pdo = new ReconnectingPdo(static function () use ($dsn, $username, $password, $options): PDO {
            $pdo = new PDO($dsn, $username, $password, $options);
            $pdo->exec("SET time_zone = '+00:00'");
            return $pdo;
        });
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
