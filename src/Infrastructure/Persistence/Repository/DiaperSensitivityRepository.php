<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Repository;

use Hub\Domain\DiaperSensitivity;
use Hub\Domain\DiaperSensitivityLookup;
use Hub\Support\TtlCache;
use PDO;

/**
 * A leitura da sensibilidade no caminho quente da ingestão, com cache curta: o TTL é a latência
 * com que uma alteração pela API se aplica. A escrita é a `DiaperSensitivityCapability`.
 */
final class DiaperSensitivityRepository implements DiaperSensitivityLookup
{
    private TtlCache $cache;

    public function __construct(private PDO $pdo, int $cacheTtlSeconds = 5)
    {
        $this->cache = new TtlCache($cacheTtlSeconds);
    }

    /** @return array{pollutionRange: int, pollutionValue: int} */
    public function forDevice(string $sensorKey): array
    {
        return $this->cache->remember($sensorKey, fn (): array => $this->load($sensorKey));
    }

    /** @return array{pollutionRange: int, pollutionValue: int} */
    private function load(string $sensorKey): array
    {
        $stmt = $this->pdo->prepare('
            SELECT desired_payload
            FROM device_configurations
            WHERE imei = ? AND config_key = ?
        ');
        $stmt->execute([$sensorKey, 'diaper_sensitivity']);
        $payload = json_decode((string)$stmt->fetchColumn(), true);

        // A ausência de linha é o preset normal e não um erro, e é por isso que não há
        // backfill: um sensor que ninguém configurou usa o preset.
        return is_array($payload)
            && isset($payload['pollutionRange'], $payload['pollutionValue'])
            ? [
                'pollutionRange' => (int)$payload['pollutionRange'],
                'pollutionValue' => (int)$payload['pollutionValue'],
            ]
            : DiaperSensitivity::normal();
    }
}
