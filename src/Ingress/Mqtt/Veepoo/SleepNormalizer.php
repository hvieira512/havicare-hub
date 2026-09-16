<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Veepoo;

/**
 * Traduz o registo de sono preciso da pulseira para os nomes do hub.
 *
 * A pulseira guarda três noites e reproduz cada uma quando lhe pedem. Ao contrário dos blocos
 * de cinco minutos, isto não é uma sequência de leituras: é o relatório de uma noite, já
 * calculado pelo firmware.
 *
 * Saem duas capacidades da mesma trama. O `sleep` é a noite -- quando começou, quando acabou,
 * e o que aconteceu pelo meio -- e é o mesmo contrato que os relógios já usam. O
 * `sleep_quality` são as pontuações que o firmware atribui à noite, que são um juízo sobre a
 * medição e não a medição.
 *
 * Os significados vêm da documentação do fabricante (`VeepooUniAppSDK`, secção 9.4) e não dos
 * nomes dos campos, que enganam: `nightScore` é a pontuação das idas à casa de banho,
 * `nightTotalTime` é quanto tempo se esteve levantado, e `sleepQuality` vem 0-4 onde a app
 * mostra 1-5 estrelas.
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
                // O nome por que se vai à documentação do fabricante, e que distingue isto do
                // sono que os blocos de cinco minutos codificam e que continua por decifrar.
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
     * As noites que a trama traz.
     *
     * A pulseira responde a um dia por pedido, mas embrulha a resposta numa lista e o gateway
     * entrega-a como veio. Um registo solto continua a ser aceite: é a forma que a
     * documentação do fabricante mostra.
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

        // A mesma regra dos relógios: o fim tem de vir depois do começo, senão os dois
        // instantes caem e fica a dizer-se que não são de confiar.
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

        $night = array_filter([
            'startTime' => $start === null ? null : self::instantFromSeconds($start),
            'endTime' => $end === null ? null : self::instantFromSeconds($end),
            'totalDurationMinutes' => $total,
            'timingValid' => $timingValid,
            'segments' => $segments === [] ? null : $segments,
        ], static fn(mixed $v): bool => $v !== null);

        // `timingValid` sozinho não é uma noite: sem duração nem troços não há nada a dizer.
        return isset($night['totalDurationMinutes']) || isset($night['segments']) ? $night : null;
    }

    /**
     * A curva como lista de valores, um por intervalo.
     *
     * A pulseira envia-a em inteiros; a documentação do fabricante mostra-a como cadeia de
     * caracteres. Aceitam-se as duas formas aqui, para o resto do código não ter de saber qual
     * delas veio.
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
     * Os troços da noite, tirados da curva.
     *
     * Um valor por intervalo, e intervalos seguidos do mesmo valor são um troço só. A duração
     * de cada intervalo sai das fronteiras da noite a dividir pelo número deles: o fabricante
     * não a declara, e assumir cinco minutos punha a curva a discordar dos instantes que a
     * própria trama traz. Na MF91 a conta dá um minuto, e as contagens de cada valor somam os
     * troços que o firmware declara à parte.
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
     * Os troços que restam quando não há curva, ou quando os instantes não são de confiar.
     *
     * O firmware dá o total de cada fase à parte da curva, e eles chegam para saber quanto se
     * dormiu de cada maneira mesmo sem saber quando. `otherSleepTime` é o tempo acordado
     * dentro da noite -- o fabricante chama-lhe «outro sono».
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

        // Primeiro, e com a escala no nome: o firmware conta de 0 a 4 e a app do fabricante
        // mostra de uma a cinco estrelas. Publicar o valor cru dava uma noite perfeita a
        // parecer uma nota de 4 em 5.
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
     * O instante que o firmware datou com mês, dia, hora e minuto -- e mais nada.
     *
     * O ano vem de quando o registo foi lido: a pulseira guarda três noites, por isso a data
     * é sempre a mais recente que não esteja no futuro. Sem isto, o sono de 31 de dezembro
     * lido a 1 de janeiro ficava onze meses à frente.
     *
     * Quatro partes que não sirvam como data devolvem `null`, e é assim que um formato
     * diferente do esperado se denuncia em vez de virar um instante inventado.
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

        // Uma folga de um dia: o gateway lê o registo depois de a noite acabar, mas os
        // relógios do aparelho e do servidor não estão ao segundo um do outro.
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
