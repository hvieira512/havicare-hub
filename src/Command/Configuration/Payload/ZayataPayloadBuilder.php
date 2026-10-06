<?php

declare(strict_types=1);

namespace Hub\Command\Configuration\Payload;

use Hub\Protocol\Adapter\PillDispenserAdapter;
use Hub\Support\Values;

/**
 * Valida o que se configura num M228 e recusa o que o aparelho recusaria: hora acima das 23,
 * mais de nove alarmes, fuso fora do mapa. A trama é do `ZayataDownlink`.
 */
final class ZayataPayloadBuilder extends ConfigurationPayloadBuilder
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function build(string $key, array $payload): array
    {
        return match ($key) {
            'medication_reminders' => ['plans' => self::plans($payload['plans'] ?? [])],
            'medication_period' => self::period($payload),
            // Os valores em falta caem nos de fábrica, e não em erro: o painel pede o payload por
            // omissão antes de alguém escolher, e esse tem de passar na validação.
            'early_dispense', 'child_lock', 'missed_dispense', 'emergency_call',
            'key_tone', 'auto_clock' => [
                'enabled' => (bool)self::boolInt($payload['enabled'] ?? false, 'enabled'),
            ],
            // Gamas do tipo de dispositivo 02: volume de 0 (mais alto) a 3 (silêncio) e toque até 3. O
            // «Ringtone 4» da tabela do 0x1012 é do tipo 01, e o aparelho recusa-o.
            'alarm_volume' => ['volume' => self::zeroBasedRangeInt($payload['volume'] ?? 0, 0, 3, 'volume')],
            'alarm_ringtone' => ['ringtone' => self::zeroBasedRangeInt($payload['ringtone'] ?? 0, 0, 3, 'ringtone')],
            'date_format' => ['format' => self::zeroBasedRangeInt($payload['format'] ?? 0, 0, 2, 'format')],
            'time_format' => ['format' => self::zeroBasedRangeInt($payload['format'] ?? 0, 0, 1, 'format')],
            'do_not_disturb' => [
                'enabled' => (bool)self::boolInt($payload['enabled'] ?? false, 'enabled'),
                'startHour' => self::zeroBasedRangeInt($payload['startHour'] ?? 22, 0, 23, 'startHour'),
                'startMinute' => self::zeroBasedRangeInt($payload['startMinute'] ?? 0, 0, 59, 'startMinute'),
                'endHour' => self::zeroBasedRangeInt($payload['endHour'] ?? 7, 0, 23, 'endHour'),
                'endMinute' => self::zeroBasedRangeInt($payload['endMinute'] ?? 0, 0, 59, 'endMinute'),
            ],
            // Os dois tempos da toma, em minutos. O limite é o do aparelho: 86400 segundos.
            'retrieval_warning', 'retrieval_timeout' => [
                'minutes' => self::zeroBasedRangeInt($payload['minutes'] ?? 0, 0, 1440, 'minutes'),
            ],
            // O prato tem 28 compartimentos, e carregados podem estar de nenhum a todos.
            'loaded_cells' => ['cells' => self::zeroBasedRangeInt($payload['cells'] ?? 0, 0, 28, 'cells')],
            // Duas línguas e mais nada: a de fábrica e o inglês.
            'device_language' => ['language' => self::zeroBasedRangeInt($payload['language'] ?? 0, 0, 1, 'language')],
            // HHMM com sinal, e não minutos: `+100` é uma hora à frente. A gama é a da
            // especificação, de −1200 a +1400.
            'time_zone' => ['timeZone' => self::zeroBasedRangeInt($payload['timeZone'] ?? 0, -1200, 1400, 'timeZone')],
            // As acções não levam payload: o que as distingue é o comando.
            default => [],
        };
    }

    /**
     * O intervalo em que o plano vale. O aparelho aceita as datas em qualquer ordem, e um
     * intervalo ao contrário nunca vale nem dá erro: os alarmes não tocam.
     *
     * @param array<string, mixed> $payload
     * @return array{enabled: bool, startDate: string, endDate: string}
     */
    private static function period(array $payload): array
    {
        $start = self::date($payload['startDate'] ?? '', 'startDate');
        $end = self::date($payload['endDate'] ?? '', 'endDate');

        // Metade preenchida não tem ordem a comparar: vazio é «sem período».
        if ($start !== '' && $end !== '' && $end < $start) {
            throw new \InvalidArgumentException('endDate must not be before startDate');
        }

        return [
            'enabled' => (bool)self::boolInt($payload['enabled'] ?? false, 'enabled'),
            'startDate' => $start,
            'endDate' => $end,
        ];
    }

    /**
     * Uma data em `AAAA-MM-DD`, ou vazio. Vazio é legítimo: quer dizer "sem período", e é o
     * interruptor que o diz ao aparelho.
     */
    private static function date(mixed $value, string $field): string
    {
        $date = is_string($value) ? trim($value) : '';
        if ($date === '') {
            return '';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new \InvalidArgumentException("{$field} must be a date as YYYY-MM-DD");
        }
        [$year, $month, $day] = array_map('intval', explode('-', $date));
        if (!checkdate($month, $day, $year)) {
            throw new \InvalidArgumentException("{$field} is not a real date");
        }

        return $date;
    }

    /**
     * @param mixed $plans
     * @return list<array{slot?: int, hour: int, minute: int, enabled: bool}>
     */
    private static function plans(mixed $plans): array
    {
        if (!is_array($plans)) {
            throw new \InvalidArgumentException('plans must be a list');
        }
        if (count($plans) > PillDispenserAdapter::ALARM_SLOTS) {
            throw new \InvalidArgumentException('the M228 has ' . PillDispenserAdapter::ALARM_SLOTS . ' alarms');
        }

        $out = [];
        foreach (array_values($plans) as $index => $plan) {
            if (!is_array($plan)) {
                throw new \InvalidArgumentException('plans items must be objects');
            }
            // O slot só viaja quando o plano o traz: sem ele, quem monta a trama coloca o
            // plano pela posição, que é a única leitura que um plano antigo permite.
            $out[] = Values::withoutNulls([
                'slot' => isset($plan['slot'])
                    ? self::zeroBasedRangeInt($plan['slot'], 1, PillDispenserAdapter::ALARM_SLOTS, "plans[{$index}].slot")
                    : null,
                'hour' => self::zeroBasedRangeInt($plan['hour'] ?? 0, 0, 23, "plans[{$index}].hour"),
                'minute' => self::zeroBasedRangeInt($plan['minute'] ?? 0, 0, 59, "plans[{$index}].minute"),
                'enabled' => (bool)self::boolInt($plan['enabled'] ?? true, "plans[{$index}].enabled"),
            ]);
        }

        return $out;
    }
}
