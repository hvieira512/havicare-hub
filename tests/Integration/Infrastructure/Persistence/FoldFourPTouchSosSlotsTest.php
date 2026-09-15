<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Migration\FoldFourPTouchSosSlots;
use PDO;
use Tests\Support\MysqlDashboardTestCase;

/**
 * A conversão das três linhas de slot numa só, quando os contactos SOS passaram a sair no
 * comando `SOS`.
 *
 * Os números já estão no relógio, e apagar as linhas sem mais deixava a dashboard a dizer que
 * o aparelho não tem contactos nenhuns.
 */
final class FoldFourPTouchSosSlotsTest extends MysqlDashboardTestCase
{
    private function insertSlot(PDO $pdo, string $imei, int $slot, string $phone, string $status): void
    {
        $pdo->prepare('
            INSERT INTO device_configurations (
                imei, config_key, native_key, protocol, command,
                desired_payload, reported_payload, last_status, desired_updated_at, applied_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            $imei, 'sos_contacts', 'sosNumber' . $slot, 'four-p-touch', 'SOS' . $slot,
            json_encode(['phone' => $phone]), '{}', $status,
            '2026-09-0' . $slot . ' 10:00:00', '2026-09-0' . $slot . ' 10:00:01',
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function rows(PDO $pdo, string $imei): array
    {
        $stmt = $pdo->prepare('
            SELECT native_key, command, desired_payload, last_status, desired_updated_at
            FROM device_configurations WHERE imei = ? ORDER BY native_key
        ');
        $stmt->execute([$imei]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function testTheThreeSlotsBecomeOneListInSlotOrder(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $this->insertSlot($pdo, '868160060298224', 1, '+351938854803', 'confirmed');
        $this->insertSlot($pdo, '868160060298224', 2, '+351965401976', 'confirmed');
        $this->insertSlot($pdo, '868160060298224', 3, '', 'confirmed');

        (new FoldFourPTouchSosSlots())->up($pdo);

        $rows = $this->rows($pdo, '868160060298224');
        self::assertCount(1, $rows);
        self::assertSame('sosContacts', $rows[0]['native_key']);
        self::assertSame('SOS', $rows[0]['command']);
        self::assertSame(
            '{"numbers":["+351938854803","+351965401976",""]}',
            $rows[0]['desired_payload']
        );
        self::assertSame('confirmed', $rows[0]['last_status']);
        self::assertSame('2026-09-03 10:00:00', $rows[0]['desired_updated_at']);
    }

    public function testASlotThatWasNeverWrittenCountsAsEmptyAndTheWeakestStatusWins(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $this->insertSlot($pdo, '861728087056333', 1, '+351924880787', 'acked');

        (new FoldFourPTouchSosSlots())->up($pdo);

        $rows = $this->rows($pdo, '861728087056333');
        self::assertCount(1, $rows);
        self::assertSame('{"numbers":["+351924880787","",""]}', $rows[0]['desired_payload']);
        self::assertSame('acked', $rows[0]['last_status']);
    }

    public function testABaseWithoutSlotRowsIsLeftAlone(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $pdo->prepare('
            INSERT INTO device_configurations (
                imei, config_key, native_key, protocol, command,
                desired_payload, reported_payload, last_status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            '861728087060467', 'center_number', 'centerNumber', 'four-p-touch', 'CENTER',
            '{"phone":"+351938854803"}', '{}', 'confirmed',
        ]);

        (new FoldFourPTouchSosSlots())->up($pdo);

        $rows = $this->rows($pdo, '861728087060467');
        self::assertCount(1, $rows);
        self::assertSame('centerNumber', $rows[0]['native_key']);
    }
}
