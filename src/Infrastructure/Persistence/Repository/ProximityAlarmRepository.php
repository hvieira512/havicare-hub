<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Repository;

use Hub\Domain\ProximityAlarm;
use Hub\Domain\ProximityAlarmLookup;
use Hub\Support\TtlCache;
use PDO;

/**
 * A leitura do alarme de proximidade no caminho quente da ingestão, um por avistamento, com cache
 * curta: o TTL é a latência com que uma alteração pela API se aplica.
 */
final class ProximityAlarmRepository implements ProximityAlarmLookup
{
    private TtlCache $cache;

    public function __construct(private PDO $pdo, int $cacheTtlSeconds = 5)
    {
        $this->cache = new TtlCache($cacheTtlSeconds);
    }

    public function forGateway(string $gatewayKey): array
    {
        return $this->cache->remember($gatewayKey, fn (): array => $this->load($gatewayKey));
    }

    /** @return array<string, mixed> */
    private function load(string $gatewayKey): array
    {
        $stmt = $this->pdo->prepare('
            SELECT desired_payload
            FROM device_configurations
            WHERE imei = ? AND config_key = ?
        ');
        $stmt->execute([$gatewayKey, 'proximity_alert']);
        $payload = json_decode((string)$stmt->fetchColumn(), true);

        // Sem linha, o alarme está desligado.
        return (is_array($payload) ? $payload : []) + ProximityAlarm::defaults();
    }
}
