<?php

declare(strict_types=1);

namespace Hub\Api\Repository;

use Hub\Domain\DiaperSensitivity;
use Hub\Domain\DiaperSensitivityLookup;
use PDO;

/**
 * A leitura da sensibilidade no caminho quente da ingestão. A escrita é a
 * `DiaperSensitivityCapability`, pelo `PATCH .../configurations` como qualquer outra.
 *
 * A cache curta é o padrão do `GatewayDeviceLinkRepository`, e o TTL é a latência com que uma
 * alteração pela API passa a ser aplicada -- sem reiniciar nada. MySQL é a fonte de verdade,
 * e por isso a cache nasce vazia depois de um reinício sem que nada se perca.
 */
final class DiaperSensitivityRepository implements DiaperSensitivityLookup
{
    // Teto do cache. A chave é o sensor, limitado pelo inventário, mas o teto fecha a porta a
    // um crescimento patológico num processo de longa vida.
    private const MAX_CACHED = 10000;

    /** @var array<string, array{settings: array{pollutionRange: int, pollutionValue: int}, loadedAt: int}> */
    private array $cache = [];

    public function __construct(private PDO $pdo, private int $cacheTtlSeconds = 5)
    {
        $this->cacheTtlSeconds = max(0, $this->cacheTtlSeconds);
    }

    /** @return array{pollutionRange: int, pollutionValue: int} */
    public function forDevice(string $sensorKey): array
    {
        $cached = $this->cache[$sensorKey] ?? null;
        if (is_array($cached) && time() - $cached['loadedAt'] <= $this->cacheTtlSeconds) {
            return $cached['settings'];
        }

        $stmt = $this->pdo->prepare('
            SELECT desired_payload
            FROM device_configurations
            WHERE imei = ? AND config_key = ?
        ');
        $stmt->execute([$sensorKey, 'diaper_sensitivity']);
        $payload = json_decode((string)$stmt->fetchColumn(), true);

        // A ausência de linha é o preset normal e não um erro, e é por isso que não há
        // backfill: um sensor que ninguém configurou usa o preset.
        $settings = is_array($payload)
            && isset($payload['pollutionRange'], $payload['pollutionValue'])
            ? [
                'pollutionRange' => (int)$payload['pollutionRange'],
                'pollutionValue' => (int)$payload['pollutionValue'],
            ]
            : DiaperSensitivity::normal();
        if (!isset($this->cache[$sensorKey]) && count($this->cache) >= self::MAX_CACHED) {
            unset($this->cache[array_key_first($this->cache)]);
        }
        $this->cache[$sensorKey] = ['settings' => $settings, 'loadedAt' => time()];

        return $settings;
    }
}
