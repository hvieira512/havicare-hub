<?php

namespace App\Repositories;

use PDO;

final class DashboardHistoryRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function appendTelemetry(string $imei, string $type, array $payload): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare('INSERT INTO telemetry (imei, type, payload, recorded_at) VALUES (?, ?, ?, ?)');
        $stmt->execute([$imei, $type, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $now]);
    }

    public function appendEvent(string $imei, string $type, array $payload): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare('INSERT INTO events (imei, type, payload, recorded_at) VALUES (?, ?, ?, ?)');
        $stmt->execute([$imei, $type, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $now]);
    }

    public function appendRaw(string $imei, array $payload): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare('INSERT INTO raw_payloads (imei, payload, recorded_at) VALUES (?, ?, ?)');
        $stmt->execute([$imei, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $now]);
    }
}
