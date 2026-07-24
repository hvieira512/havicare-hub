<?php

declare(strict_types=1);

namespace Hub\Api\Repository;

use Hub\Infrastructure\Persistence\TimestampFormatter;
use PDO;

final class DashboardNotificationRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function record(
        string $type,
        string $imei,
        string $protocol,
        string $model,
        string $ident,
        string $reason,
    ): void {
        $stmt = $this->pdo->prepare('
            INSERT INTO dashboard_notifications (
                type, imei, protocol, model, ident, reason
            )
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                model = VALUES(model),
                ident = VALUES(ident),
                reason = VALUES(reason),
                occurrence_count = occurrence_count + 1,
                last_seen_at = CURRENT_TIMESTAMP,
                read_at = NULL
        ');
        $stmt->execute([$type, $imei, $protocol, $model, $ident, $reason]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function latest(int $limit): array
    {
        $stmt = $this->pdo->prepare('
            SELECT
                id, type, imei, protocol, model, ident, reason,
                occurrence_count, first_seen_at, last_seen_at, read_at
            FROM dashboard_notifications
            ORDER BY last_seen_at DESC, id DESC
            LIMIT ?
        ');
        $stmt->bindValue(1, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'normalize'], $stmt->fetchAll());
    }

    public function unreadCount(): int
    {
        return (int)$this->pdo
            ->query('SELECT COUNT(*) FROM dashboard_notifications WHERE read_at IS NULL')
            ->fetchColumn();
    }

    /**
     * @param list<int> $ids
     */
    public function markRead(array $ids): void
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "UPDATE dashboard_notifications SET read_at = CURRENT_TIMESTAMP WHERE id IN ($placeholders)"
        );
        $stmt->execute($ids);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM dashboard_notifications WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'type' => (string)($row['type'] ?? ''),
            'imei' => (string)($row['imei'] ?? ''),
            'protocol' => (string)($row['protocol'] ?? ''),
            'model' => (string)($row['model'] ?? ''),
            'ident' => (string)($row['ident'] ?? ''),
            'reason' => (string)($row['reason'] ?? ''),
            'occurrenceCount' => (int)($row['occurrence_count'] ?? 0),
            'firstSeenAt' => TimestampFormatter::toIso((string)($row['first_seen_at'] ?? '')),
            'lastSeenAt' => TimestampFormatter::toIso((string)($row['last_seen_at'] ?? '')),
            'readAt' => ($row['read_at'] ?? null) === null
                ? null
                : TimestampFormatter::toIso((string)$row['read_at']),
        ];
    }
}
