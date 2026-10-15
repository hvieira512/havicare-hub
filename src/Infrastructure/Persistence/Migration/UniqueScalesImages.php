<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * As fotografias das balanças da Unique Scales, nas bases onde os modelos ficaram sem nenhuma. Os
 * ficheiros vêm de `database/seed-model-images`, copiados pelo migrador.
 */
final class UniqueScalesImages implements Migration
{
    private const IMAGES = [
        'HC-CF632BLE' => '50754bfc972b5d94a5af67695b01fffb.jpg',
        'HC-CF621BLE' => '1d024ba99d692a5fefbc8dad0dce563d.jpg',
    ];

    public function version(): string
    {
        return '2026_10_15_unique_scales_images';
    }

    public function up(PDO $pdo): void
    {
        $stmt = $pdo->prepare("
            UPDATE models m JOIN suppliers s ON s.id = m.supplier_id
            SET m.image_path = ?
            WHERE s.name = 'Unique Scales' AND m.internal_model = ? AND m.image_path = ''
        ");
        foreach (self::IMAGES as $model => $image) {
            $stmt->execute([$image, $model]);
        }
    }
}
