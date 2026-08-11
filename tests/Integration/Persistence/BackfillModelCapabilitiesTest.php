<?php

declare(strict_types=1);

namespace Tests\Integration\Persistence;

use Hub\Infrastructure\Persistence\Migration\Version2026081102BackfillMissingModelCapabilities;
use PDO;
use Tests\Support\MysqlDashboardTestCase;

/**
 * A capability added to a protocol after a model was seeded never reached that
 * model, because the seeder skips models that already have rows. The device
 * then supports something the API refuses to configure.
 */
final class BackfillModelCapabilitiesTest extends MysqlDashboardTestCase
{
    /** @return array{PDO, int} */
    private function vivistarWatch(): array
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $stmt = $pdo->prepare('
            SELECT m.id FROM models m JOIN suppliers s ON s.id = m.supplier_id
            WHERE s.name = ? AND m.internal_model = ?
        ');
        $stmt->execute(['Vivistar', 'L08 Pro']);
        $id = (int)($stmt->fetchColumn() ?: 0);
        self::assertGreaterThan(0, $id, 'the Vivistar watch should be seeded');

        return [$pdo, $id];
    }

    /** @return array<string, int> capability key => enabled */
    private function capabilities(PDO $pdo, int $modelId): array
    {
        $stmt = $pdo->prepare('
            SELECT c.capability_key, mc.enabled
            FROM model_capabilities mc
            JOIN capabilities c ON c.id = mc.capability_id
            WHERE mc.model_id = ?
        ');
        $stmt->execute([$modelId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    public function testAModelGainsACapabilityItsProtocolSupports(): void
    {
        [$pdo, $modelId] = $this->vivistarWatch();
        $pdo->prepare('
            DELETE mc FROM model_capabilities mc
            JOIN capabilities c ON c.id = mc.capability_id
            WHERE mc.model_id = ? AND c.capability_key = ?
        ')->execute([$modelId, 'fall_sensitivity']);
        self::assertArrayNotHasKey('fall_sensitivity', $this->capabilities($pdo, $modelId));

        (new Version2026081102BackfillMissingModelCapabilities())->up($pdo);

        // Vivistar watches have BP77; refusing to configure it was the bug.
        self::assertSame(1, $this->capabilities($pdo, $modelId)['fall_sensitivity'] ?? null);
    }

    public function testACapabilitySwitchedOffByHandStaysOff(): void
    {
        [$pdo, $modelId] = $this->vivistarWatch();
        $pdo->prepare('
            UPDATE model_capabilities mc
            JOIN capabilities c ON c.id = mc.capability_id
            SET mc.enabled = 0
            WHERE mc.model_id = ? AND c.capability_key = ?
        ')->execute([$modelId, 'blood_pressure']);
        self::assertSame(0, $this->capabilities($pdo, $modelId)['blood_pressure'] ?? null);

        (new Version2026081102BackfillMissingModelCapabilities())->up($pdo);

        // Filling gaps must not undo a deliberate decision.
        self::assertSame(0, $this->capabilities($pdo, $modelId)['blood_pressure'] ?? null);
    }

    public function testRunningItTwiceChangesNothingFurther(): void
    {
        [$pdo, $modelId] = $this->vivistarWatch();
        $migration = new Version2026081102BackfillMissingModelCapabilities();

        $migration->up($pdo);
        $first = $this->capabilities($pdo, $modelId);
        $migration->up($pdo);

        self::assertSame($first, $this->capabilities($pdo, $modelId));
    }
}
