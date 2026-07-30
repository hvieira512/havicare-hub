<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

final class Version2026073002CanonicalizeFourPTouchContactSlots implements Migration
{
    private const GENERIC_KEYS = [
        'sosNumber1' => 'sos_contacts',
        'sosNumber2' => 'sos_contacts',
        'sosNumber3' => 'sos_contacts',
        'whitelistGroup1' => 'call_whitelist',
        'whitelistGroup2' => 'call_whitelist',
    ];

    public function version(): string
    {
        return '2026073002_canonicalize_four_p_touch_contact_slots';
    }

    public function up(PDO $pdo): void
    {
        $rows = $pdo->query("
            SELECT *
            FROM device_configurations
            WHERE protocol = 'four-p-touch'
              AND native_key IN (
                  'sosNumber1', 'sosNumber2', 'sosNumber3',
                  'whitelistGroup1', 'whitelistGroup2'
              )
            ORDER BY imei, native_key
        ")->fetchAll(PDO::FETCH_ASSOC);

        $latestBySlot = [];
        foreach ($rows as $row) {
            $nativeKey = (string)($row['native_key'] ?? '');
            if (!isset(self::GENERIC_KEYS[$nativeKey])) {
                continue;
            }
            $identity = implode("\0", [(string)($row['imei'] ?? ''), $nativeKey]);
            $existing = $latestBySlot[$identity] ?? null;
            if ($existing === null || $this->isNewer($row, $existing, self::GENERIC_KEYS[$nativeKey])) {
                $latestBySlot[$identity] = $row;
            }
        }

        if ($latestBySlot === []) {
            return;
        }

        $delete = $pdo->prepare("
            DELETE FROM device_configurations
            WHERE imei = ?
              AND protocol = 'four-p-touch'
              AND native_key = ?
        ");
        $insert = $pdo->prepare('
            INSERT INTO device_configurations (
                imei, config_key, native_key, protocol, supplier, model, command,
                desired_payload, reported_payload, last_status, last_command_id,
                desired_updated_at, reported_at, applied_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');

        $pdo->beginTransaction();
        try {
            foreach ($latestBySlot as $row) {
                $nativeKey = (string)$row['native_key'];
                $delete->execute([(string)$row['imei'], $nativeKey]);
                $insert->execute([
                    (string)$row['imei'],
                    self::GENERIC_KEYS[$nativeKey],
                    $nativeKey,
                    (string)$row['protocol'],
                    (string)($row['supplier'] ?? ''),
                    (string)($row['model'] ?? ''),
                    (string)($row['command'] ?? ''),
                    (string)($row['desired_payload'] ?? '{}'),
                    (string)($row['reported_payload'] ?? '{}'),
                    (string)($row['last_status'] ?? ''),
                    (string)($row['last_command_id'] ?? ''),
                    (string)($row['desired_updated_at'] ?? ''),
                    (string)($row['reported_at'] ?? ''),
                    (string)($row['applied_at'] ?? ''),
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function isNewer(array $candidate, array $existing, string $genericKey): bool
    {
        $candidateTimestamp = $this->rowTimestamp($candidate);
        $existingTimestamp = $this->rowTimestamp($existing);
        if ($candidateTimestamp !== $existingTimestamp) {
            return strcmp($candidateTimestamp, $existingTimestamp) > 0;
        }

        return (string)($candidate['config_key'] ?? '') === $genericKey
            && (string)($existing['config_key'] ?? '') !== $genericKey;
    }

    private function rowTimestamp(array $row): string
    {
        return max(
            (string)($row['desired_updated_at'] ?? ''),
            (string)($row['reported_at'] ?? ''),
            (string)($row['applied_at'] ?? '')
        );
    }
}
