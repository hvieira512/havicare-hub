<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Repository;

use Hub\Domain\ProximityAlarm;
use Hub\Domain\ProximityAlarmLookup;
use PDO;

/**
 * A leitura do alarme de proximidade no caminho quente da ingestão, um por avistamento, com cache
 * curta: o TTL é a latência com que uma alteração pela API se aplica.
 */
final class ProximityAlarmRepository implements ProximityAlarmLookup
{
    private const MAX_CACHED = 10000;

    /** @var array<string, array{settings: array<string, mixed>, loadedAt: int}> */
    private array $cache = [];

    public function __construct(private PDO $pdo, private int $cacheTtlSeconds = 5)
    {
        $this->cacheTtlSeconds = max(0, $this->cacheTtlSeconds);
    }

    public function forGateway(string $gatewayKey): array
    {
        $cached = $this->cache[$gatewayKey] ?? null;
        if (is_array($cached) && time() - $cached['loadedAt'] <= $this->cacheTtlSeconds) {
            return $cached['settings'];
        }

        $stmt = $this->pdo->prepare('
            SELECT desired_payload
            FROM device_configurations
            WHERE imei = ? AND config_key = ?
        ');
        $stmt->execute([$gatewayKey, 'proximity_alert']);
        $payload = json_decode((string)$stmt->fetchColumn(), true);

        // Sem linha, o alarme está desligado.
        $settings = (is_array($payload) ? $payload : []) + ProximityAlarm::defaults();
        if (!isset($this->cache[$gatewayKey]) && count($this->cache) >= self::MAX_CACHED) {
            unset($this->cache[array_key_first($this->cache)]);
        }
        $this->cache[$gatewayKey] = ['settings' => $settings, 'loadedAt' => time()];

        return $settings;
    }
}
