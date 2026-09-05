<?php

declare(strict_types=1);

namespace Hub\Api\Repository;

use Hub\Domain\GatewayDeviceLinkLookup;
use Hub\Infrastructure\Persistence\TimestampFormatter;
use PDO;

final class GatewayDeviceLinkRepository implements GatewayDeviceLinkLookup
{
    // Teto do cache. A chave é o par gateway|aparelho, limitado pelo inventário, mas o teto
    // fecha a porta a um crescimento patológico num processo de longa vida.
    private const MAX_CACHED = 10000;

    /** @var array<string, array{enabled: bool, loadedAt: int}> */
    private array $authorizationCache = [];

    public function __construct(private PDO $pdo, private int $cacheTtlSeconds = 5)
    {
        $this->cacheTtlSeconds = max(0, $this->cacheTtlSeconds);
    }

    public function isEnabled(string $gatewayDeviceKey, string $linkedDeviceKey): bool
    {
        $cacheKey = $gatewayDeviceKey . ':' . $linkedDeviceKey;
        $cached = $this->authorizationCache[$cacheKey] ?? null;
        if (is_array($cached) && time() - $cached['loadedAt'] <= $this->cacheTtlSeconds) {
            return $cached['enabled'];
        }
        $stmt = $this->pdo->prepare('SELECT enabled FROM gateway_device_links WHERE gateway_device_key = ? AND linked_device_key = ?');
        $stmt->execute([$gatewayDeviceKey, $linkedDeviceKey]);
        $enabled = (int)($stmt->fetchColumn() ?: 0) === 1;
        if (!isset($this->authorizationCache[$cacheKey]) && count($this->authorizationCache) >= self::MAX_CACHED) {
            unset($this->authorizationCache[array_key_first($this->authorizationCache)]);
        }
        $this->authorizationCache[$cacheKey] = ['enabled' => $enabled, 'loadedAt' => time()];
        return $enabled;
    }

    public function upsert(string $gatewayDeviceKey, string $linkedDeviceKey): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO gateway_device_links (gateway_device_key, linked_device_key, enabled)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE enabled = 1, updated_at = CURRENT_TIMESTAMP
        ');
        $stmt->execute([$gatewayDeviceKey, $linkedDeviceKey]);
        unset($this->authorizationCache[$gatewayDeviceKey . ':' . $linkedDeviceKey]);
    }

    public function delete(string $gatewayDeviceKey, string $linkedDeviceKey): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM gateway_device_links WHERE gateway_device_key = ? AND linked_device_key = ?');
        $stmt->execute([$gatewayDeviceKey, $linkedDeviceKey]);
        unset($this->authorizationCache[$gatewayDeviceKey . ':' . $linkedDeviceKey]);
    }

    /** @return list<array<string, mixed>> */
    public function forDevice(string $deviceKey): array
    {
        $stmt = $this->pdo->prepare('
            SELECT
                l.gateway_device_key AS gatewayDeviceKey,
                l.linked_device_key AS linkedDeviceKey,
                l.enabled,
                l.created_at,
                l.updated_at,
                CASE WHEN l.gateway_device_key = ? THEN l.linked_device_key ELSE l.gateway_device_key END AS deviceKey,
                w.supplier, w.model, w.device_type AS deviceType,
                w.license_id AS licenseId, w.company
            FROM gateway_device_links l
            JOIN whitelist w ON w.imei = CASE
                WHEN l.gateway_device_key = ? THEN l.linked_device_key
                ELSE l.gateway_device_key
            END
            WHERE l.gateway_device_key = ? OR l.linked_device_key = ?
            ORDER BY deviceKey
        ');
        $stmt->execute([$deviceKey, $deviceKey, $deviceKey, $deviceKey]);
        return TimestampFormatter::normalizeRows($stmt->fetchAll() ?: []);
    }
}
