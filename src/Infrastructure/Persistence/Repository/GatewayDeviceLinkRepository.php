<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Repository;

use Hub\Domain\GatewayDeviceLinkLookup;
use Hub\Infrastructure\Persistence\TimestampFormatter;
use Hub\Support\TtlCache;
use PDO;

final class GatewayDeviceLinkRepository implements GatewayDeviceLinkLookup
{
    private TtlCache $authorizationCache;

    public function __construct(private PDO $pdo, int $cacheTtlSeconds = 5)
    {
        $this->authorizationCache = new TtlCache($cacheTtlSeconds);
    }

    public function isEnabled(string $gatewayDeviceKey, string $linkedDeviceKey): bool
    {
        return $this->authorizationCache->remember(
            $gatewayDeviceKey . ':' . $linkedDeviceKey,
            fn (): bool => $this->loadEnabled($gatewayDeviceKey, $linkedDeviceKey),
        );
    }

    private function loadEnabled(string $gatewayDeviceKey, string $linkedDeviceKey): bool
    {
        $stmt = $this->pdo->prepare('SELECT enabled FROM gateway_device_links WHERE gateway_device_key = ? AND linked_device_key = ?');
        $stmt->execute([$gatewayDeviceKey, $linkedDeviceKey]);

        return (int)($stmt->fetchColumn() ?: 0) === 1;
    }

    public function upsert(string $gatewayDeviceKey, string $linkedDeviceKey): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO gateway_device_links (gateway_device_key, linked_device_key, enabled)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE enabled = 1, updated_at = CURRENT_TIMESTAMP
        ');
        $stmt->execute([$gatewayDeviceKey, $linkedDeviceKey]);
        $this->authorizationCache->forget($gatewayDeviceKey . ':' . $linkedDeviceKey);
    }

    public function delete(string $gatewayDeviceKey, string $linkedDeviceKey): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM gateway_device_links WHERE gateway_device_key = ? AND linked_device_key = ?');
        $stmt->execute([$gatewayDeviceKey, $linkedDeviceKey]);
        $this->authorizationCache->forget($gatewayDeviceKey . ':' . $linkedDeviceKey);
    }

    /** @return list<array<string, mixed>> */
    public function forDevice(string $deviceKey): array
    {
        $stmt = $this->pdo->prepare('
            SELECT
                l.gateway_device_key AS gatewayDeviceKey,
                l.linked_device_key AS linkedDeviceKey,
                l.enabled,
                l.created_at AS createdAt,
                l.updated_at AS updatedAt,
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
        return array_map(
            static fn (array $row): array => TimestampFormatter::isoColumns($row, ['createdAt', 'updatedAt']),
            $stmt->fetchAll() ?: [],
        );
    }
}
