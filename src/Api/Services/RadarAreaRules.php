<?php

declare(strict_types=1);

namespace Hub\Api\Services;

use Hub\Command\Configuration\Payload\QinglanstPayloadBuilder;

/** As regras das áreas de um radar contra a sala que ele tem: o que a dashboard mostra é o que isto recusa. */
final class RadarAreaRules
{
    /** A porta conta como encostada até 20 cm da parede. */
    private const DOOR_WALL_TOLERANCE_DM = 2;

    /**
     * Os motivos por área (`items[i]`) e pela lista (`items`); vazio quando tudo cumpre.
     *
     * @param list<array<string, mixed>> $items
     * @param array{xMinDm: int, yMinDm: int, xMaxDm: int, yMaxDm: int}|null $room
     * @return array<string, list<string>>
     */
    public static function violations(array $items, ?array $room): array
    {
        $violations = [];
        if (count($items) > QinglanstPayloadBuilder::MAX_AREAS) {
            $violations['items'][] = 'too_many_areas';
        }

        $monitoringBeds = 0;
        foreach ($items as $index => $item) {
            $type = $item['type'] ?? null;
            if ($type === 'monitoring_bed' && ++$monitoringBeds > 1) {
                $violations["items[{$index}]"][] = 'second_monitoring_bed';
            }
            if ($room === null) {
                continue;
            }
            if ($type === 'door' && self::distanceToWall($item, $room) > self::DOOR_WALL_TOLERANCE_DM) {
                $violations["items[{$index}]"][] = 'door_off_wall';
            } elseif ($type !== 'door' && !self::inside($item, $room)) {
                $violations["items[{$index}]"][] = 'outside_room';
            }
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $area
     * @param array{xMinDm: int, yMinDm: int, xMaxDm: int, yMaxDm: int} $room
     */
    private static function distanceToWall(array $area, array $room): int
    {
        return min(
            abs((int)($area['xMinDm'] ?? 0) - $room['xMinDm']),
            abs((int)($area['xMaxDm'] ?? 0) - $room['xMaxDm']),
            abs((int)($area['yMinDm'] ?? 0) - $room['yMinDm']),
            abs((int)($area['yMaxDm'] ?? 0) - $room['yMaxDm']),
        );
    }

    /**
     * @param array<string, mixed> $area
     * @param array{xMinDm: int, yMinDm: int, xMaxDm: int, yMaxDm: int} $room
     */
    private static function inside(array $area, array $room): bool
    {
        return (int)($area['xMinDm'] ?? 0) >= $room['xMinDm']
            && (int)($area['xMaxDm'] ?? 0) <= $room['xMaxDm']
            && (int)($area['yMinDm'] ?? 0) >= $room['yMinDm']
            && (int)($area['yMaxDm'] ?? 0) <= $room['yMaxDm'];
    }
}
