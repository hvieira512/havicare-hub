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

    /** A espera pelo fim do envio do histórico inclui a transferência dos registos. */
    private const HISTORY_TRANSFER_MS = 10000;

    private const MALE = 1;

    private const FEMALE = 0;

    private const ATHLETE = 1;

    private const NOT_ATHLETE = 0;

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
            'selectScaleUser' => self::flow('06', '07', self::ids($payload), $mtu),
            'registerScaleUser' => self::flow('01', '02', self::ids($payload) . self::profile($payload), $mtu),
            'deleteScaleUser' => self::flow('03', '04', self::ids($payload), $mtu),
            'readScaleHistory' => self::history($payload, $mtu),
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
     * Um fluxo de utilizador no `FFF2`: `05 03 <início> <comprimento LE>`, o corpo em pacotes
     * `00 <n> …` do tamanho do MTU, e `05 01 <fim>`. Cada passo espera a resposta `05 02 <passo>`.
     *
     * @return list<array<string, mixed>>
     */
    private static function flow(string $start, string $end, string $body, int $mtu): array
    {
        return [
            self::awaiting('0503' . $start . bin2hex(pack('v', strlen($body))), '0502' . $start),
            ...self::chunks($body, $mtu),
            self::awaiting('0501' . $end, '0502' . $end),
        ];
    }

    /**
     * O histórico de um membro: pede-se, a balança manda os registos e `02 02 0a 01`, e
     * confirma-se a receção.
     *
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private static function history(array $payload, int $mtu): array
    {
        $member = self::id($payload['id'] ?? null, 'id');

        return [
            self::awaiting('0203074000', '020207'),
            ...self::chunks($member, $mtu),
            self::awaiting('020108', '020208'),
            self::awaiting('020109', '02020a01', self::HISTORY_TRANSFER_MS),
            ['char' => 'fff2', 'hex' => '02020a00'],
        ];
    }

    /** @return array<string, mixed> */
    private static function awaiting(string $hex, string $prefix, int $timeoutMs = self::AWAIT_MS): array
    {
        return ['char' => 'fff2', 'hex' => $hex, 'await' => ['char' => 'fff2', 'prefix' => $prefix, 'timeoutMs' => $timeoutMs]];
    }

    /** @return list<array<string, mixed>> */
    private static function chunks(string $body, int $mtu): array
    {
        $writes = [];
        foreach (str_split($body, max(1, $mtu - 2)) as $index => $chunk) {
            $writes[] = ['char' => 'fff2', 'hex' => sprintf('00%02x', $index) . bin2hex($chunk)];
        }

        return $writes;
    }

    /**
     * O mesmo identificador do hub como `userID` e como `memberID`.
     *
     * @param array<string, mixed> $payload
     */
    private static function ids(array $payload): string
    {
        $id = self::id($payload['id'] ?? null, 'id');

        return $id . $id;
    }

    /**
     * `idade sexo altura(LE) atleta peso×100(LE)`: o registo mínimo, que a CF632 aceita.
     *
     * @param array<string, mixed> $payload
     */
    private static function profile(array $payload): string
    {
        return pack(
            'CCvCv',
            (int)($payload['ageYears'] ?? 0),
            ($payload['sex'] ?? null) === 'male' ? self::MALE : self::FEMALE,
            (int)($payload['heightCm'] ?? 0),
            ($payload['athlete'] ?? false) === true ? self::ATHLETE : self::NOT_ATHLETE,
            (int)round((float)($payload['weightKg'] ?? 0) * 100),
        );
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
