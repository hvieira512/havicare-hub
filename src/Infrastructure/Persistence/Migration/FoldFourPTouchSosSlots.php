<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * Junta os três slots de SOS da 4P Touch numa linha só.
 *
 * O firmware aceita `SOS,n1,n2,n3` e define a lista inteira de uma vez, e o hub deixou de ter
 * `sosNumber1`, `sosNumber2` e `sosNumber3` como chaves nativas separadas. As linhas antigas
 * não podem ficar: têm a mesma capacidade genérica da linha nova e o detalhe do dispositivo
 * somava as duas formas.
 *
 * Os números são recuperados em vez de apagados -- estão nos relógios, e uma base sem eles
 * mostrava contactos vazios em aparelhos que ligam para alguém.
 */
final class FoldFourPTouchSosSlots implements Migration
{
    private const STATUS_ORDER = ['confirmed' => 2, 'acked' => 1];

    public function version(): string
    {
        return '2026_09_15_fold_four_p_touch_sos_slots';
    }

    public function up(PDO $pdo): void
    {
        $slots = $pdo
            ->query("
                SELECT imei, native_key, desired_payload, last_status, desired_updated_at, applied_at
                FROM device_configurations
                WHERE native_key IN ('sosNumber1', 'sosNumber2', 'sosNumber3')
                ORDER BY imei, native_key
            ")
            ->fetchAll(PDO::FETCH_ASSOC);
        if ($slots === []) {
            return;
        }

        $folded = [];
        foreach ($slots as $slot) {
            $imei = (string)$slot['imei'];
            $index = (int)substr((string)$slot['native_key'], -1) - 1;
            $payload = json_decode((string)$slot['desired_payload'], true);
            $folded[$imei]['numbers'][$index] = is_array($payload) ? trim((string)($payload['phone'] ?? '')) : '';
            $folded[$imei]['status'][] = (string)$slot['last_status'];
            $folded[$imei]['updatedAt'][] = $slot['desired_updated_at'];
            $folded[$imei]['appliedAt'][] = $slot['applied_at'];
        }

        $insert = $pdo->prepare('
            INSERT INTO device_configurations (
                imei, config_key, native_key, protocol, command,
                desired_payload, reported_payload, last_status, desired_updated_at, applied_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE desired_payload = VALUES(desired_payload)
        ');
        foreach ($folded as $imei => $device) {
            $numbers = [];
            foreach ([0, 1, 2] as $index) {
                $numbers[] = $device['numbers'][$index] ?? '';
            }
            $insert->execute([
                $imei, 'sos_contacts', 'sosContacts', 'four-p-touch', 'SOS',
                json_encode(['numbers' => $numbers]), '{}',
                $this->weakestStatus($device['status']),
                max($device['updatedAt']),
                max($device['appliedAt']),
            ]);
        }

        $pdo->exec("
            DELETE FROM device_configurations
            WHERE native_key IN ('sosNumber1', 'sosNumber2', 'sosNumber3')
        ");
    }

    /**
     * O estado da lista é o do slot que menos provou: um número confirmado não torna confirmado
     * o vizinho que o relógio nunca reconheceu.
     *
     * @param list<string> $statuses
     */
    private function weakestStatus(array $statuses): string
    {
        $weakest = 'confirmed';
        foreach ($statuses as $status) {
            if ((self::STATUS_ORDER[$status] ?? 0) < (self::STATUS_ORDER[$weakest] ?? 0)) {
                $weakest = $status;
            }
        }

        return $weakest;
    }
}
