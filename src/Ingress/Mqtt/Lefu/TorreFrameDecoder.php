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
        $bin = (string)hex2bin($hex);
        $bytes = array_values(unpack('C*', $bin) ?: []);

        return match (strtolower($characteristic)) {
            'fff3' => $this->measurement($bytes, $bin),
            'fff1' => $this->command($bytes),
            'fff2' => $this->user($bytes, $bin),
            default => null,
        };
    }

    /**
     * @param list<int> $b
     * @return array<string, mixed>|null
     */
    private function measurement(array $b, string $bin): ?array
    {
        return match ($b[0] ?? null) {
            0x01, 0x11 => count($b) >= 15 ? $this->weighing($bin) : null,
            0x02 => count($b) >= 12 ? [
                'kind' => 'encoded_impedance',
                'electrodes' => self::electrodes($b[3]),
                'encoded50Khz' => self::unpacked('V', $bin, 4),
                'encoded100Khz' => self::unpacked('V', $bin, 8),
            ] : null,
            0x03 => count($b) >= 6 ? [
                'kind' => 'impedance_channel',
                'channel' => $b[3],
                'impedanceOhm' => round(self::unpacked('v', $bin, 4) / 10, 1),
            ] : null,
            default => null,
        };
    }

    /**
     * A trama `01`. Os estados vêm em pares de bits do byte 2, e a FC e a impedância só valem
     * quando o seu par diz que foram medidas.
     *
     * @return array<string, mixed>
     */
    private function weighing(string $bin): array
    {
        $b = unpack('Ctype/x/Cstate/Cflags/vweight/Cunit/CheartRate/x2/vimpedance50/x/vimpedance100', $bin) ?: [];
        $state = $b['state'];
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
            'complete' => ($b['flags'] & 0x80) !== 0,
            'weightKg' => round($b['weight'] / 100, 2),
            'heartRateBpm' => $heartRate === 'measured' && $b['heartRate'] > 0 ? $b['heartRate'] : null,
            'impedance50KhzOhm' => $impedance === 'measured' ? round($b['impedance50'] / 10, 1) : null,
            'impedance100KhzOhm' => $impedance === 'measured' ? round($b['impedance100'] / 10, 1) : null,
            'status' => [
                'weightPhase' => $weight,
                'impedancePhase' => $impedance,
                'heartRatePhase' => $heartRate,
                'displayUnit' => self::UNITS[$b['unit']] ?? null,
                'shuttingDown' => ($b['flags'] & 0x40) !== 0,
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
    private function user(array $b, string $bin): ?array
    {
        $flow = match ($b[0] ?? null) {
            0x05 => 'user',
            0x02 => 'history',
            default => null,
        };
        if ($flow === null || ($b[1] ?? null) !== 0x02 || count($b) !== 4) {
            return $this->history($bin);
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
     * @return array<string, mixed>|null
     */
    private function history(string $bin): ?array
    {
        $count = strlen($bin) - self::ID_BYTES;
        if ($count < self::HISTORY_RECORD || $count % self::HISTORY_RECORD !== 0) {
            return null;
        }

        $records = [];
        for ($at = self::ID_BYTES; $at < strlen($bin); $at += self::HISTORY_RECORD) {
            $r = unpack('Vat/vweight/CheartRate/Celectrodes/Vencoded50/vimpedance50/Vencoded100/vimpedance100', $bin, $at) ?: [];
            $records[] = [
                'at' => $r['at'],
                'weightKg' => round($r['weight'] / 100, 2),
                'heartRateBpm' => $r['heartRate'] > 0 && $r['heartRate'] < 0xFF ? $r['heartRate'] : null,
                'electrodes' => self::electrodes($r['electrodes']),
                'encodedImpedance50Khz' => $r['encoded50'],
                'impedance50KhzOhm' => round($r['impedance50'] / 10, 1),
                'encodedImpedance100Khz' => $r['encoded100'],
                'impedance100KhzOhm' => round($r['impedance100'] / 10, 1),
            ];
        }

        return [
            'kind' => 'history',
            'memberId' => rtrim(substr($bin, 0, self::ID_BYTES), "\0"),
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

    /** Um inteiro little-endian, `v` de 16 bits ou `V` de 32. */
    private static function unpacked(string $format, string $bin, int $at): int
    {
        return (int)(unpack($format, $bin, $at) ?: [1 => 0])[1];
    }
}
