<?php

declare(strict_types=1);

namespace Hub\Domain\Capability;

/**
 * As marcas de uma leitura que quem aplica limites não deve contar: `fromHistory` na que não é de
 * agora, e `implausible` na que não é de uma pessoa viva. Só aparecem a `true`.
 */
final class ReadingMarks
{
    /** Mais velha do que isto à chegada, a leitura é a memória do aparelho a ser descarregada. */
    private const LIVE_SECONDS = 1800;

    /** Abaixo disto a temperatura corporal é uma medição falhada, em qualquer sítio do corpo. */
    private const MIN_BODY_CELSIUS = 34.0;

    /**
     * @param array<string, mixed> $reading
     * @return array<string, mixed>
     */
    public static function mark(array $reading, ?int $now = null): array
    {
        $data = is_array($reading['data'] ?? null) ? $reading['data'] : [];
        $measuredAt = strtotime((string)($reading['occurredAt'] ?? ''));
        if ($measuredAt !== false && $measuredAt < ($now ?? time()) - self::LIVE_SECONDS) {
            $data['fromHistory'] = true;
        }
        $body = $data['bodyCelsius'] ?? null;
        if (($reading['type'] ?? null) === 'temperature' && is_numeric($body) && $body < self::MIN_BODY_CELSIUS) {
            $data['implausible'] = true;
        }

        if ($data !== ($reading['data'] ?? [])) {
            $reading['data'] = $data;
        }

        return $reading;
    }
}
