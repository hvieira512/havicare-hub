<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Lefu;

use DateTimeImmutable;

/**
 * As escritas GATT de cada operação, que o gateway faz pela ordem. Os fluxos do `FFF2` só
 * avançam com a resposta de cada passo, e é isso que o `await` diz ao gateway.
 */
final class TorreCommandEncoder
{
    private const AWAIT_MS = 3000;

    /** O `userID` e o `memberID` ocupam 64 bytes cada, completados com zeros. */
    private const ID_BYTES = 64;

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    public function writes(string $operation, array $payload, int $mtu, DateTimeImmutable $now, int $offsetMinutes): array
    {
        return match ($operation) {
            'weightUnit' => [self::fff1(sprintf('050201%02x', self::unitCode($payload['unit'] ?? null)))],
            'heartRateMeasurement' => [self::fff1('060201' . self::switchByte($payload))],
            'impedanceMeasurement' => [self::fff1('070201' . self::switchByte($payload))],
            'calibrateClock' => [self::fff1(self::clock($now, $offsetMinutes))],
            'syncConfiguration' => [self::fff1('05020200'), self::fff1('06020200'), self::fff1('07020200')],
            'restartDevice' => [self::fff1('0900')],
            'selectScaleUser' => self::selectUser($payload, $mtu),
            default => throw new \InvalidArgumentException("Unsupported lefu-torre operation {$operation}"),
        };
    }

    /** @return array{char: string, hex: string} */
    private static function fff1(string $hex): array
    {
        return ['char' => 'fff1', 'hex' => $hex];
    }

    /**
     * No fio, `00` liga e `01` desliga.
     *
     * @param array<string, mixed> $payload
     */
    private static function switchByte(array $payload): string
    {
        return ($payload['enabled'] ?? false) === true ? '00' : '01';
    }

    private static function unitCode(mixed $unit): int
    {
        $code = array_search($unit, TorreFrameDecoder::UNITS, true);
        if ($code === false) {
            throw new \InvalidArgumentException('unit must be one of ' . implode(', ', TorreFrameDecoder::UNITS));
        }

        return $code;
    }

    /**
     * `04 06 <segundos LE> <fuso> <formato>`: os segundos levam a parte do fuso abaixo da hora,
     * o fuso vai em horas com o bit 7 como sinal, e o formato `01` é 24 horas.
     */
    private static function clock(DateTimeImmutable $now, int $offsetMinutes): string
    {
        $seconds = $now->getTimestamp() + ($offsetMinutes % 60) * 60;
        $hours = intdiv(abs($offsetMinutes), 60) | ($offsetMinutes < 0 ? 0x80 : 0);

        return '0406' . bin2hex(pack('V', $seconds)) . sprintf('%02x', $hours) . '01';
    }

    /**
     * `05 03 06 <comprimento LE>`, os dois identificadores em pacotes `00 <n> …` do tamanho do
     * MTU, e `05 01 07`.
     *
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private static function selectUser(array $payload, int $mtu): array
    {
        $body = self::id($payload['userId'] ?? null, 'userId') . self::id($payload['memberId'] ?? null, 'memberId');
        $writes = [[
            'char' => 'fff2',
            'hex' => '050306' . bin2hex(pack('v', strlen($body))),
            'await' => ['char' => 'fff2', 'prefix' => '050206', 'timeoutMs' => self::AWAIT_MS],
        ]];
        foreach (str_split($body, max(1, $mtu - 2)) as $index => $chunk) {
            $writes[] = ['char' => 'fff2', 'hex' => sprintf('00%02x', $index) . bin2hex($chunk)];
        }
        $writes[] = [
            'char' => 'fff2',
            'hex' => '050107',
            'await' => ['char' => 'fff2', 'prefix' => '050207', 'timeoutMs' => self::AWAIT_MS],
        ];

        return $writes;
    }

    private static function id(mixed $value, string $field): string
    {
        $id = is_string($value) ? trim($value) : '';
        if ($id === '' || strlen($id) > self::ID_BYTES || !mb_check_encoding($id, 'ASCII')) {
            throw new \InvalidArgumentException("{$field} must be ASCII, 1 to " . self::ID_BYTES . ' characters');
        }

        return str_pad($id, self::ID_BYTES, "\0");
    }
}
