<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\JoyTech;

use DateTimeImmutable;

/** As escritas no `FFF2` de cada operação dos aparelhos JoyTech, que o gateway faz pela ordem. */
final class JoyTechCommandEncoder
{
    /** A sonda leu 175 registos em 15 s. */
    private const MEMORY_TRANSFER_MS = 30000;

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    public function writes(string $operation, array $payload, int $mtu, DateTimeImmutable $now, int $offsetMinutes): array
    {
        return match ($operation) {
            'calibrateClock' => [['char' => 'fff2', 'hex' => JoyTechFrame::encode(0x04, self::localTime($now, $offsetMinutes))]],
            'readBloodPressureHistory' => [[
                'char' => 'fff2',
                'hex' => JoyTechFrame::encode(0x0a),
                'await' => ['char' => 'fff1', 'prefix' => substr(JoyTechFrame::encode(0x8b), 0, 14), 'timeoutMs' => self::MEMORY_TRANSFER_MS],
            ]],
            // O ECG do DBP-6679B: começar na contagem, e confirmar o fim.
            'startEcg' => [['char' => 'fff2', 'hex' => JoyTechFrame::encode(0x05)]],
            'acknowledgeEcg' => [['char' => 'fff2', 'hex' => JoyTechFrame::encode(0x06)]],
            // O termómetro: a hora pedida por ele, e a confirmação da memória, que não a apaga.
            'answerClock' => [['char' => 'fff2', 'hex' => JoyTechFrame::encode(0x86, self::localTime($now, $offsetMinutes))]],
            'acknowledgeMeasurements' => [['char' => 'fff2', 'hex' => JoyTechFrame::encode(0x83)]],
            default => throw new \InvalidArgumentException("Unsupported JoyTech operation {$operation}"),
        };
    }

    /** O aparelho guarda a hora local, com o ano em dois dígitos. */
    private static function localTime(DateTimeImmutable $now, int $offsetMinutes): string
    {
        $local = array_map('intval', explode(' ', gmdate('y n j G i', $now->getTimestamp() + $offsetMinutes * 60)));

        return pack('C5', ...$local);
    }
}
