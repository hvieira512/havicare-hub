<?php

declare(strict_types=1);

namespace Hub\Api\Repository;

use Hub\Domain\DiaperSensitivity;
use Hub\Domain\DiaperSensitivityLookup;
use PDO;

/**
 * A sensibilidade por sensor, lida de onde vivem todas as configurações.
 *
 * Teve tabela própria -- `diaper_sensor_settings` -- enquanto não era uma capacidade.
 * Agora é a `DiaperSensitivityCapability`, gravada pelo `PATCH .../configurations` como
 * qualquer outra, e o que fica aqui é a leitura no caminho quente da ingestão.
 *
 * A cache curta é a mesma ideia do `GatewayDeviceLinkRepository`: a autorização
 * gateway/sensor já é lida da base de dados a cada observação com este padrão, e ele já
 * provou aguentar produção. O TTL é também a latência com que uma alteração pela API passa
 * a ser aplicada pela ingestão -- sem reiniciar nada, e sem a API ter de falar com o
 * processo do hub.
 *
 * MySQL é a fonte de verdade precisamente por causa dos reinícios: a cache nasce vazia e
 * reenche na primeira observação, e nada se perde.
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

        $stmt = $this->pdo->prepare('
            SELECT desired_payload
            FROM device_configurations
            WHERE imei = ? AND config_key = ?
        ');
        $stmt->execute([$sensorKey, 'diaper_sensitivity']);
        $payload = json_decode((string)$stmt->fetchColumn(), true);

        // A ausência de linha é o preset normal e não um erro: é o comportamento com que o
        // hub sempre correu, e é por isso que nenhuma migração fez backfill.
        $settings = is_array($payload)
            && isset($payload['pollutionRange'], $payload['pollutionValue'])
            ? [
                'pollutionRange' => (int)$payload['pollutionRange'],
                'pollutionValue' => (int)$payload['pollutionValue'],
            ]
            : DiaperSensitivity::normal();
        $this->cache[$sensorKey] = ['settings' => $settings, 'loadedAt' => time()];

        return $settings;
    }
}
