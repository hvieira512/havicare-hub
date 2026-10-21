<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Lefu;

/**
 * As notificações GATT do protocolo Torre da Lefu, que a balança CF632 fala. Cada trama é
 * `tipo, comprimento, dados`; o detalhe está em `docs/fornecedores/UniqueScales/README.md`.
 */
final class TorreFrameDecoder
{
    public const UNITS = [0 => 'kg', 1 => 'lb', 2 => 'jin', 3 => 'st', 4 => 'st_lb'];

    private const ID_BYTES = 64;

    private const HISTORY_RECORD = 20;

    /** O resultado dos passos de utilizador no `FFF2`; `00` é sucesso. */
    private const USER_ERRORS = [1 => 'failed', 2 => 'user_selection_in_progress', 3 => 'missing_name', 4 => 'memory_full'];

    /** As definições que se leem no `FFF1`, pelo comando. */
    private const READABLE = [0x05 => 'weight_unit', 0x06 => 'heart_rate_measurement', 0x07 => 'impedance_measurement'];

    /** @return array<string, mixed>|null */
    public function decode(string $characteristic, string $hex): ?array
    {
        $hex = preg_replace('/\s+/', '', $hex) ?? '';
        if ($hex === '' || strlen($hex) % 2 !== 0 || !ctype_xdigit($hex)) {
            return null;
        }
        $bytes = array_values(unpack('C*', (string)hex2bin($hex)) ?: []);

        return match (strtolower($characteristic)) {
            'fff3' => $this->measurement($bytes),
            'fff1' => $this->command($bytes),
            'fff2' => $this->user($bytes),
            default => null,
        };
    }

    /**
     * @param list<int> $b
     * @return array<string, mixed>|null
     */
    private function measurement(array $b): ?array
    {
        return match ($b[0] ?? null) {
            0x01, 0x11 => count($b) >= 15 ? $this->weighing($b) : null,
            0x02 => count($b) >= 12 ? [
                'kind' => 'encoded_impedance',
                'electrodes' => self::electrodes($b[3]),
                'encoded50Khz' => self::uint32($b, 4),
                'encoded100Khz' => self::uint32($b, 8),
            ] : null,
            0x03 => count($b) >= 6 ? [
                'kind' => 'impedance_channel',
                'channel' => $b[3],
                'impedanceOhm' => round(self::uint16($b, 4) / 10, 1),
            ] : null,
            default => null,
        };
    }

    /**
     * A trama `01`. Os estados vêm em pares de bits do byte 2, e a FC e a impedância só valem
     * quando o seu par diz que foram medidas.
     *
     * @param list<int> $b
     * @return array<string, mixed>
     */
    private function weighing(array $b): array
    {
        $state = $b[2];
        $impedance = match ($state & 0x30) {
            0x10 => 'measuring',
            0x20 => 'measured',
            0x30 => 'failed',
            default => 'idle',
        };
        $heartRate = match ($state & 0xC0) {
            0x40 => 'measuring',
            0x80 => 'measured',
            0xC0 => 'failed',
            default => 'idle',
        };
        // Os dois bits acesos vêm com a balança vazia: ninguém em cima.
        $weight = match ($state & 0x0C) {
            0x0C => 'idle',
            0x08 => 'overload',
            0x04 => 'stable',
            default => 'settling',
        };

        return [
            'kind' => 'weighing',
            'complete' => ($b[3] & 0x80) !== 0,
            'weightKg' => round(self::uint16($b, 4) / 100, 2),
            'heartRateBpm' => $heartRate === 'measured' && $b[7] > 0 ? $b[7] : null,
            'impedance50KhzOhm' => $impedance === 'measured' ? round(self::uint16($b, 10) / 10, 1) : null,
            'impedance100KhzOhm' => $impedance === 'measured' ? round(self::uint16($b, 13) / 10, 1) : null,
            'status' => [
                'weight' => $weight,
                'impedance' => $impedance,
                'heartRate' => $heartRate,
                'displayUnit' => self::UNITS[$b[6]] ?? null,
                'shuttingDown' => ($b[3] & 0x40) !== 0,
            ],
        ];
    }

    /**
     * O `FFF1` responde a uma escrita com `<cmd> 02 01 <resultado>` e a uma leitura com
     * `<cmd> 02 02 <valor>`; o relógio responde `04 01 <resultado>`.
     *
     * @param list<int> $b
     * @return array<string, mixed>|null
     */
    private function command(array $b): ?array
    {
        $command = $b[0] ?? null;
        if ($command === null || $command === 0x02) {
            return null;
        }
        $replyType = sprintf('torre_%02x', $command);

        if (($b[1] ?? null) === 0x01 && isset($b[2])) {
            return ['kind' => 'reply', 'replyType' => $replyType . '_set', 'accepted' => $b[2] === 0x00];
        }
        if (($b[1] ?? null) !== 0x02 || !isset($b[3])) {
            return null;
        }
        if ($b[2] === 0x01) {
            return ['kind' => 'reply', 'replyType' => $replyType . '_set', 'accepted' => $b[3] === 0x00];
        }
        if ($b[2] !== 0x02 || !isset(self::READABLE[$command])) {
            return null;
        }

        $key = self::READABLE[$command];

        return [
            'kind' => 'setting',
            'replyType' => $replyType . '_read',
            'settings' => [
                $key => $key === 'weight_unit' ? ['unit' => self::UNITS[$b[3]] ?? null] : ['enabled' => $b[3] === 0x00],
            ],
        ];
    }

    /**
     * @param list<int> $b
     * @return array<string, mixed>|null
     */
    private function user(array $b): ?array
    {
        $flow = match ($b[0] ?? null) {
            0x05 => 'user',
            0x02 => 'history',
            default => null,
        };
        if ($flow === null || ($b[1] ?? null) !== 0x02 || count($b) !== 4) {
            return $this->history($b);
        }

        // O fim do envio do histórico responde `01`: é o sinal, e não uma falha.
        $accepted = $b[3] === 0x00 || ($flow === 'history' && $b[2] === 0x0a);

        return [
            'kind' => 'reply',
            'replyType' => sprintf('torre_%s_%02x', $flow, $b[2]),
            'accepted' => $accepted,
            'error' => $accepted ? null : (self::USER_ERRORS[$b[3]] ?? 'failed'),
        ];
    }

    /**
     * Os pacotes do histórico, já juntos pelo gateway: o membro (64 bytes) e registos de 20 bytes
     * -- data, peso, FC, tipo, e as duas impedâncias, cifrada e em claro.
     *
     * @param list<int> $b
     * @return array<string, mixed>|null
     */
    private function history(array $b): ?array
    {
        $count = count($b) - self::ID_BYTES;
        if ($count < self::HISTORY_RECORD || $count % self::HISTORY_RECORD !== 0) {
            return null;
        }

        $records = [];
        for ($at = self::ID_BYTES; $at < count($b); $at += self::HISTORY_RECORD) {
            $heartRate = $b[$at + 6];
            $records[] = [
                'at' => self::uint32($b, $at),
                'weightKg' => round(self::uint16($b, $at + 4) / 100, 2),
                'heartRateBpm' => $heartRate > 0 && $heartRate < 0xFF ? $heartRate : null,
                'electrodes' => self::electrodes($b[$at + 7]),
                'encodedImpedance50Khz' => self::uint32($b, $at + 8),
                'impedance50KhzOhm' => round(self::uint16($b, $at + 12) / 10, 1),
                'encodedImpedance100Khz' => self::uint32($b, $at + 14),
                'impedance100KhzOhm' => round(self::uint16($b, $at + 18) / 10, 1),
            ];
        }

        return [
            'kind' => 'history',
            'memberId' => rtrim(implode('', array_map('chr', array_slice($b, 0, self::ID_BYTES))), "\0"),
            'records' => $records,
        ];
    }

    private static function electrodes(int $type): string
    {
        return match ($type) {
            0x01 => 'four',
            0x02 => 'four_dual_frequency',
            default => 'eight',
        };
    }

    /** @param list<int> $b */
    private static function uint16(array $b, int $at): int
    {
        return $b[$at] | ($b[$at + 1] << 8);
    }

    /** @param list<int> $b */
    private static function uint32(array $b, int $at): int
    {
        return $b[$at] | ($b[$at + 1] << 8) | ($b[$at + 2] << 16) | ($b[$at + 3] << 24);
    }
}
