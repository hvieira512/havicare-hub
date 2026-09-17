<?php

namespace Hub\Api\Repository;

use PDO;

/**
 * A planta de cada radar: a divisão e as áreas declaradas no aparelho.
 *
 * A `area_key` é a chave do fabricante, e é também o `regionId` que a telemetria de presença
 * reporta -- é por ela que se sabe em que cama está quem lá está.
 */
final class RadarLayoutRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Grava a planta inteira, sala e áreas, de uma vez.
     *
     * As áreas são apagadas antes de entrarem as novas, e dentro da mesma transação: uma sala
     * reconfigurada com menos áreas deixava as antigas para trás, e o mapa passava a desenhar
     * uma cama que já ninguém declarou.
     *
     * @param array{room: array<string, int>, areas: list<array<string, int|string>>} $layout
     */
    public function store(string $imei, array $layout, string $sourcePayload, string $fetchedAt): void
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare('
                INSERT INTO radar_layouts
                    (imei, room_x_min_dm, room_y_min_dm, room_x_max_dm, room_y_max_dm, source_payload, fetched_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    room_x_min_dm = VALUES(room_x_min_dm),
                    room_y_min_dm = VALUES(room_y_min_dm),
                    room_x_max_dm = VALUES(room_x_max_dm),
                    room_y_max_dm = VALUES(room_y_max_dm),
                    source_payload = VALUES(source_payload),
                    fetched_at = VALUES(fetched_at)
            ');
            $stmt->execute([
                $imei,
                $layout['room']['x_min_dm'],
                $layout['room']['y_min_dm'],
                $layout['room']['x_max_dm'],
                $layout['room']['y_max_dm'],
                $sourcePayload,
                $fetchedAt,
            ]);

            $stmt = $this->pdo->prepare('DELETE FROM radar_layout_areas WHERE imei = ?');
            $stmt->execute([$imei]);

            $stmt = $this->pdo->prepare('
                INSERT INTO radar_layout_areas
                    (imei, area_key, area_type, name, x_min_dm, y_min_dm, x_max_dm, y_max_dm)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            foreach ($layout['areas'] as $area) {
                $stmt->execute([
                    $imei,
                    $area['key'],
                    $area['type'],
                    $area['name'],
                    $area['x_min_dm'],
                    $area['y_min_dm'],
                    $area['x_max_dm'],
                    $area['y_max_dm'],
                ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }

    /** @return array<string, mixed>|null */
    public function findByImei(string $imei): ?array
    {
        $layouts = $this->findByImeis([$imei]);

        return $layouts[$imei] ?? null;
    }

    /**
     * As plantas de vários radares de uma vez, indexadas pelo IMEI.
     *
     * @param list<string> $imeis
     * @return array<string, array<string, mixed>>
     */
    public function findByImeis(array $imeis): array
    {
        $imeis = array_values(array_unique(array_filter($imeis, static fn(string $imei): bool => $imei !== '')));
        if ($imeis === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($imeis), '?'));

        $stmt = $this->pdo->prepare("
            SELECT imei, room_x_min_dm, room_y_min_dm, room_x_max_dm, room_y_max_dm, fetched_at
            FROM radar_layouts WHERE imei IN ($placeholders) ORDER BY imei
        ");
        $stmt->execute($imeis);

        $layouts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $layouts[(string)$row['imei']] = [
                'room' => [
                    'x_min_dm' => (int)$row['room_x_min_dm'],
                    'y_min_dm' => (int)$row['room_y_min_dm'],
                    'x_max_dm' => (int)$row['room_x_max_dm'],
                    'y_max_dm' => (int)$row['room_y_max_dm'],
                ],
                'areas' => [],
                'fetched_at' => (string)$row['fetched_at'],
            ];
        }

        if ($layouts === []) {
            return [];
        }

        $stmt = $this->pdo->prepare("
            SELECT imei, area_key, area_type, name, x_min_dm, y_min_dm, x_max_dm, y_max_dm
            FROM radar_layout_areas WHERE imei IN ($placeholders) ORDER BY imei, area_key
        ");
        $stmt->execute($imeis);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $layouts[(string)$row['imei']]['areas'][] = [
                'key' => (int)$row['area_key'],
                'type' => (int)$row['area_type'],
                'name' => (string)$row['name'],
                'x_min_dm' => (int)$row['x_min_dm'],
                'y_min_dm' => (int)$row['y_min_dm'],
                'x_max_dm' => (int)$row['x_max_dm'],
                'y_max_dm' => (int)$row['y_max_dm'],
            ];
        }

        return $layouts;
    }
}
