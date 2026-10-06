<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Veepoo;

use Hub\Support\Values;

/**
 * Traduz o relatório de uma noite, calculado pelo firmware, em `sleep` e `sleep_quality`. Os
 * significados vêm do `VeepooUniAppSDK` §9.4, e não dos nomes dos campos, que enganam.
 */
final class SleepNormalizer
{
    /** Os valores da curva, tal como o fabricante os documenta. */
    private const CURVE_TYPES = [
        '0' => 'deep_sleep',
        '1' => 'light_sleep',
        '2' => 'rem',
        '3' => 'insomnia',
        '4' => 'awake',
    ];

    /**
     * As pontuações e as contagens, com o nome do que medem.
     *
     * @var array<string, string>
     */
    private const QUALITY = [
        'deepSleepScore' => 'deepSleepScore',
        'sleepEfficiencyScore' => 'efficiencyScore',
        'fallAsleepEfficiencyScore' => 'fallAsleepScore',
        'sleepTimeScore' => 'durationScore',
        'nightScore' => 'nightWakingScore',
        'insomniaScore' => 'insomniaScore',
        'insomniaCount' => 'awakeningCount',
        'firstDeepSleepTime' => 'firstDeepSleepMinutes',
        'nightTotalTime' => 'nightAwakeMinutes',
        'nightDeepSleepMeanValue' => 'returnToDeepSleepMeanMinutes',
    ];

    /**
     * @param array<string, mixed> $content o `payload` da trama `sleep` do gateway
     * @param array<string, mixed> $device  identidade já resolvida pelo hub
     * @return list<array<string, mixed>>   envelopes de telemetria, prontos a publicar
     */
    public static function normalize(
        array $content,
        array $device,
        string $gatewayId,
        int $tzOffsetMinutes = 0,
        ?int $now = null,
    ): array {
        $envelope = static fn(string $type, array $data): array => [
            'type' => $type,
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'device' => $device,
            'source' => [
                'protocol' => 'veepoo-ble',
                // O nome na documentação do fabricante, que distingue isto do sono dos blocos.
                'nativeType' => 'precise_sleep',
                'gatewayId' => $gatewayId,
            ],
            'data' => $data,
        ];

        $out = [];
        foreach (self::nights($content) as $record) {
            $sleep = self::night($record, $now ?? time(), $tzOffsetMinutes);
            if ($sleep !== null) {
                $out[] = $envelope('sleep', $sleep);
            }

            $quality = self::quality($record);
            if ($quality !== []) {
                $out[] = $envelope('sleep_quality', $quality);
            }
        }

        return $out;
    }

    /**
     * As noites que a trama traz: numa lista, como a pulseira envia, ou num registo solto,
     * como a documentação mostra.
     *
     * @param array<mixed> $content
     * @return list<array<string, mixed>>
     */
    private static function nights(array $content): array
    {
        if ($content === []) {
            return [];
        }

        return array_is_list($content)
            ? array_values(array_filter($content, is_array(...)))
            : [$content];
    }

    /**
     * A noite: os instantes, a duração e os troços.
     *
     * @param array<string, mixed> $content
     * @return array<string, mixed>|null
     */
    private static function night(array $content, int $now, int $tzOffsetMinutes): ?array
    {
        $total = self::minutes($content['sleepTotalTime'] ?? null);
        $start = self::instant($content['fallAsleepTime'] ?? null, $now, $tzOffsetMinutes);
        $end = self::instant($content['exitSleepTime'] ?? null, $now, $tzOffsetMinutes);

        // Como nos relógios: um fim antes do começo derruba os dois instantes.
        $timingValid = $start !== null && $end !== null && $end > $start;
        if (!$timingValid) {
            $start = null;
            $end = null;
        }

        $segments = $timingValid
            ? self::curveSegments(self::curveSlots($content['sleepCurve'] ?? null), $start, $end)
            : [];
        if ($segments === []) {
            $segments = self::totalSegments($content);
        }

        $night = Values::withoutNulls([
            'startTime' => $start === null ? null : self::instantFromSeconds($start),
            'endTime' => $end === null ? null : self::instantFromSeconds($end),
            'totalDurationMinutes' => $total,
            'timingValid' => $timingValid,
            'segments' => $segments === [] ? null : $segments,
        ]);

        // `timingValid` sozinho não é uma noite: sem duração nem troços não há nada a dizer.
        return isset($night['totalDurationMinutes']) || isset($night['segments']) ? $night : null;
    }

    /**
     * A curva como lista de valores, um por intervalo; chega em inteiros ou, como a
     * documentação mostra, em cadeia de caracteres.
     *
     * @return list<string>
     */
    private static function curveSlots(mixed $curve): array
    {
        if (is_array($curve)) {
            return array_values(array_map(static fn(mixed $v): string => (string)$v, $curve));
        }

        return is_string($curve) && $curve !== '' ? str_split($curve) : [];
    }

    /**
     * Os troços da noite, com intervalos seguidos do mesmo valor num só. A duração de cada
     * intervalo, que o fabricante não declara, é a noite a dividir pelo número deles.
     *
     * @param list<string> $curve
     * @return list<array<string, mixed>>
     */
    private static function curveSegments(array $curve, int $start, int $end): array
    {
        $slots = count($curve);
        if ($slots === 0) {
            return [];
        }

        $seconds = ($end - $start) / $slots;
        $out = [];
        $from = 0;
        for ($i = 1; $i <= $slots; $i++) {
            if ($i < $slots && $curve[$i] === $curve[$from]) {
                continue;
            }

            $type = self::CURVE_TYPES[$curve[$from]] ?? null;
            if ($type !== null) {
                $segmentStart = $start + (int)round($from * $seconds);
                $segmentEnd = $start + (int)round($i * $seconds);
                $out[] = [
                    'startTime' => self::instantFromSeconds($segmentStart),
                    'endTime' => self::instantFromSeconds($segmentEnd),
                    'durationMinutes' => (int)round(($segmentEnd - $segmentStart) / 60),
                    'type' => $type,
                ];
            }

            $from = $i;
        }

        return $out;
    }

    /**
     * Os totais por fase, sem quando, se não há curva ou os instantes não são de confiar.
     * `otherSleepTime` é o tempo acordado dentro da noite.
     *
     * @param array<string, mixed> $content
     * @return list<array<string, mixed>>
     */
    private static function totalSegments(array $content): array
    {
        $out = [];
        foreach (['deepSleepTime' => 'deep_sleep', 'lightSleepTime' => 'light_sleep', 'otherSleepTime' => 'awake'] as $field => $type) {
            $minutes = self::minutes($content[$field] ?? null);
            if ($minutes !== null) {
                $out[] = ['type' => $type, 'durationMinutes' => $minutes];
            }
        }

        return $out;
    }

    /**
     * As pontuações da noite.
     *
     * @param array<string, mixed> $content
     * @return array<string, int>
     */
    private static function quality(array $content): array
    {
        $out = [];

        // Com a escala no nome: o firmware conta de 0 a 4, e a app mostra de uma a cinco estrelas.
        $stars = self::count($content['sleepQuality'] ?? null);
        if ($stars !== null && $stars <= 4) {
            $out['qualityStars'] = $stars + 1;
        }

        foreach (self::QUALITY as $field => $name) {
            $value = self::count($content[$field] ?? null);
            if ($value !== null) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /**
     * O instante como o contrato o mostra: ISO-8601 em UTC, como o `occurredAt`.
     */
    private static function instantFromSeconds(int $seconds): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $seconds);
    }

    /**
     * O instante datado só com mês, dia, hora e minuto: o ano é o da data mais recente que não
     * esteja no futuro.
     */
    private static function instant(mixed $value, int $now, int $tzOffsetMinutes): ?int
    {
        if (!is_string($value) || !preg_match('/^(\d{2})-(\d{2})-(\d{2})-(\d{2})$/', $value, $m)) {
            return null;
        }

        [, $month, $day, $hour, $minute] = array_map('intval', $m);
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31 || $hour > 23 || $minute > 59) {
            return null;
        }

        $year = (int)gmdate('Y', $now);
        // A pulseira escreve a hora do relógio dela; o hub publica em UTC.
        $offset = $tzOffsetMinutes * 60;
        $at = gmmktime($hour, $minute, 0, $month, $day, $year);
        if ($at === false) {
            return null;
        }

        $at -= $offset;

        // Um dia de folga: os relógios do aparelho e do servidor não estão acertados ao segundo.
        if ($at <= $now + 86400) {
            return $at;
        }

        $earlier = gmmktime($hour, $minute, 0, $month, $day, $year - 1);

        return $earlier === false ? null : $earlier - $offset;
    }

    /** Uma duração em minutos, ou `null` se o campo não trouxer uma. */
    private static function minutes(mixed $value): ?int
    {
        return self::count($value);
    }

    /** Um inteiro não negativo, que é a forma de todos os números desta trama. */
    private static function count(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        return is_string($value) && ctype_digit($value) ? (int)$value : null;
    }
}
