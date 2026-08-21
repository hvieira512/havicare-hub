<?php

declare(strict_types=1);

namespace Hub\Api\Repository;

use Hub\Domain\DiaperSensitivity;
use Hub\Domain\DiaperSensitivityLookup;
use PDO;

/**
 * A sensibilidade por sensor, com a forma do `GatewayDeviceLinkRepository`.
 *
 * A cópia é deliberada: a autorização gateway/sensor já é lida da base de dados a
 * cada observação no caminho quente da ingestão, com uma cache curta em memória, e
 * esse padrão já provou aguentar produção. O TTL é também a latência com que uma
 * alteração pela API passa a ser aplicada pela ingestão -- sem reiniciar nada, e sem
 * a API ter de falar com o processo do hub.
 *
 * MySQL é a fonte de verdade precisamente por causa dos reinícios: a cache nasce
 * vazia e reenche na primeira observação, e nada se perde. Guardar isto na
 * `Whitelist`, que é um mapa carregado em memória no arranque, deixaria os dois
 * processos a discordar sobre a sensibilidade do mesmo sensor até ao reinício.
 */
final class DiaperSensitivityRepository implements DiaperSensitivityLookup
{
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

        $stmt = $this->pdo->prepare('SELECT pollution_range, pollution_value FROM diaper_sensor_settings WHERE imei = ?');
        $stmt->execute([$sensorKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        // A ausência de linha é o preset normal e não um erro: é o comportamento com
        // que o hub sempre correu, e é por isso que a migração não faz backfill.
        $settings = is_array($row)
            ? ['pollutionRange' => (int)$row['pollution_range'], 'pollutionValue' => (int)$row['pollution_value']]
            : DiaperSensitivity::normal();
        $this->cache[$sensorKey] = ['settings' => $settings, 'loadedAt' => time()];

        return $settings;
    }

    public function upsert(string $sensorKey, int $pollutionRange, int $pollutionValue): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO diaper_sensor_settings (imei, pollution_range, pollution_value)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                pollution_range = VALUES(pollution_range),
                pollution_value = VALUES(pollution_value),
                updated_at = CURRENT_TIMESTAMP
        ');
        $stmt->execute([$sensorKey, $pollutionRange, $pollutionValue]);
        unset($this->cache[$sensorKey]);
    }

    public function delete(string $sensorKey): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM diaper_sensor_settings WHERE imei = ?');
        $stmt->execute([$sensorKey]);
        unset($this->cache[$sensorKey]);
    }
}
