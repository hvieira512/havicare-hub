<?php

declare(strict_types=1);

namespace Tests\Integration\Persistence;

use Hub\Infrastructure\Persistence\ReferenceCatalogSeeder;
use PDO;
use Tests\Support\MysqlDashboardTestCase;

/**
 * Uma capacidade acrescentada ao catálogo depois de um modelo ser semeado tem de lhe
 * chegar. Enquanto o seeder saltava os modelos que já tinham linhas, não chegava: o
 * aparelho suportava a coisa e a API recusava-se a configurá-la.
 *
 * Isto foi uma migração (`2026081102_backfill_missing_model_capabilities`), escrita
 * porque na altura a semeadura só sabia encher modelos vazios. Agora enche lacunas e corre
 * a cada `migrate`, por isso o mesmo problema deixa de precisar de uma migração de cada
 * vez que acontece -- e o teste passa a apontar para onde o comportamento vive.
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

    private function fillGaps(PDO $pdo): void
    {
        (new ReferenceCatalogSeeder())->seedMissingModelCapabilities($pdo);
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

        $this->fillGaps($pdo);

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

        $this->fillGaps($pdo);

        // Filling gaps must not undo a deliberate decision.
        self::assertSame(0, $this->capabilities($pdo, $modelId)['blood_pressure'] ?? null);
    }

    public function testRunningItTwiceChangesNothingFurther(): void
    {
        [$pdo, $modelId] = $this->vivistarWatch();

        $this->fillGaps($pdo);
        $first = $this->capabilities($pdo, $modelId);
        $this->fillGaps($pdo);

        self::assertSame($first, $this->capabilities($pdo, $modelId));
    }

    public function testAModelThatWasNeverSeededGetsItsWholeTemplate(): void
    {
        // O outro lado da mesma função: um modelo criado no separador Catálogo entra sem
        // capacidade nenhuma, e os cartões dele ficavam vazios até alguém as ligar à mão.
        $pdo = $this->createDashboardDatabase()->pdo();
        $supplier = $pdo->query("SELECT id FROM suppliers WHERE name = 'Vivistar'")->fetchColumn();
        $pdo->prepare('
            INSERT INTO models (supplier_id, internal_model, commercial_name, device_type, image_path)
            VALUES (?, ?, ?, ?, \'\')
        ')->execute([(int)$supplier, 'VL99', 'Vivistar VL99', 'watch']);
        $modelId = (int)$pdo->lastInsertId();
        self::assertSame([], $this->capabilities($pdo, $modelId));

        $this->fillGaps($pdo);

        self::assertNotSame([], $this->capabilities($pdo, $modelId));
        self::assertSame(1, $this->capabilities($pdo, $modelId)['heart_rate'] ?? null);
    }
}
