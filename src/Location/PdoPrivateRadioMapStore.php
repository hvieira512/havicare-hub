<?php

declare(strict_types=1);

namespace Hub\Location;

use PDO;

final class PdoPrivateRadioMapStore implements PrivateRadioMapStoreContract
{
    // Teto do cache: a chave é o hash do BSSID, e sem limite cresce com cada ponto de acesso
    // distinto que o processo de longa vida chega a ver.
    private const MAX_CACHED = 10000;

    /** @var array<string, array{expiresAt: float, entry: ?array}> */
    private array $cache = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $cacheTtlSeconds = 60,
    ) {
    }

    public function findMany(array $bssidHashes): array
    {
        $hashes = array_values(array_unique(array_filter(
            $bssidHashes,
            static fn (mixed $hash): bool => is_string($hash) && preg_match('/^[a-f0-9]{64}$/', $hash) === 1,
        )));
        if ($hashes === []) {
            return [];
        }

        $now = microtime(true);
        $found = [];
        $missing = [];
        foreach ($hashes as $hash) {
            $cached = $this->cache[$hash] ?? null;
            if ($cached !== null && $cached['expiresAt'] >= $now) {
                if ($cached['entry'] !== null) {
                    $found[$hash] = $cached['entry'];
                }
                continue;
            }
            unset($this->cache[$hash]);
            $missing[] = $hash;
        }

        if ($missing === []) {
            return $found;
        }

        $placeholders = implode(',', array_fill(0, count($missing), '?'));
        $stmt = $this->pdo->prepare("
            SELECT bssid_hash, latitude, longitude, accuracy_meters,
                   observation_count, source, conflicted, first_seen_at, last_seen_at
            FROM private_radio_map_access_points
            WHERE bssid_hash IN ({$placeholders})
        ");
        $stmt->execute($missing);
        $loaded = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $hash = (string)$row['bssid_hash'];
            $entry = $this->normalize($row);
            $loaded[$hash] = $entry;
            $found[$hash] = $entry;
        }

        $expiresAt = $now + max(0, $this->cacheTtlSeconds);
        foreach ($missing as $hash) {
            if (!isset($this->cache[$hash]) && count($this->cache) >= self::MAX_CACHED) {
                unset($this->cache[array_key_first($this->cache)]);
            }
            $this->cache[$hash] = ['expiresAt' => $expiresAt, 'entry' => $loaded[$hash] ?? null];
        }

        return $found;
    }

    public function save(string $bssidHash, array $entry): void
    {
        $this->saveMany([$bssidHash => $entry]);
    }

    public function saveMany(array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $rows = [];
        $parameters = [];
        $normalizedEntries = [];
        foreach ($entries as $bssidHash => $entry) {
            if (!is_string($bssidHash) || preg_match('/^[a-f0-9]{64}$/', $bssidHash) !== 1 || !is_array($entry)) {
                throw new \InvalidArgumentException('Invalid private radio-map entry');
            }
            $normalized = $this->normalize($entry);
            $normalizedEntries[$bssidHash] = $normalized;
            $rows[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?)';
            array_push(
                $parameters,
                $bssidHash,
                $normalized['lat'],
                $normalized['lon'],
                $normalized['accuracyMeters'],
                $normalized['observationCount'],
                $normalized['source'],
                $normalized['conflicted'] ? 1 : 0,
                $normalized['firstSeenAt'],
                $normalized['lastSeenAt'],
            );
        }

        $stmt = $this->pdo->prepare(''
            . 'INSERT INTO private_radio_map_access_points '
            . '(bssid_hash, latitude, longitude, accuracy_meters, observation_count, source, conflicted, first_seen_at, last_seen_at) '
            . 'VALUES ' . implode(', ', $rows) . ' '
            . 'ON DUPLICATE KEY UPDATE latitude = VALUES(latitude), longitude = VALUES(longitude), '
            . 'accuracy_meters = VALUES(accuracy_meters), observation_count = VALUES(observation_count), '
            . 'source = VALUES(source), conflicted = VALUES(conflicted), first_seen_at = VALUES(first_seen_at), '
            . 'last_seen_at = VALUES(last_seen_at)');
        $stmt->execute($parameters);
        $expiresAt = microtime(true) + max(0, $this->cacheTtlSeconds);
        foreach ($normalizedEntries as $bssidHash => $normalized) {
            $this->cache[$bssidHash] = ['expiresAt' => $expiresAt, 'entry' => $normalized];
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalize(array $row): array
    {
        return [
            'lat' => (float)($row['lat'] ?? $row['latitude'] ?? 0.0),
            'lon' => (float)($row['lon'] ?? $row['longitude'] ?? 0.0),
            'accuracyMeters' => (float)($row['accuracyMeters'] ?? $row['accuracy_meters'] ?? 0.0),
            'observationCount' => max(1, (int)($row['observationCount'] ?? $row['observation_count'] ?? 1)),
            'source' => ($row['source'] ?? 'learned') === 'manual' ? 'manual' : 'learned',
            'conflicted' => (bool)($row['conflicted'] ?? false),
            'firstSeenAt' => (string)($row['firstSeenAt'] ?? $row['first_seen_at'] ?? gmdate('Y-m-d H:i:s')),
            'lastSeenAt' => (string)($row['lastSeenAt'] ?? $row['last_seen_at'] ?? gmdate('Y-m-d H:i:s')),
        ];
    }
}
