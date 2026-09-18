<?php

namespace Hub\Command\Configuration\Payload;

/**
 * Valida o que se configura num dispensador Zayata M228.
 *
 * Não monta a trama -- isso é do `DeviceCommandCatalog`, que conhece as TAGs. O que este
 * construtor faz é recusar aqui o que o aparelho recusaria lá: uma hora acima das 23, um
 * plano com mais alarmes do que os nove que ele tem, um fuso fora do mapa. O protocolo
 * responde a um valor ilegal com um estado no TFLV que ninguém está a ler ainda, e por isso
 * a validação tem de ser nossa.
 */
final class ZayataPayloadBuilder extends ConfigurationPayloadBuilder
{
    /** O aparelho tem nove alarmes fixos. */
    private const ALARM_SLOTS = 9;

    public static function build(string $key, array $payload): array
    {
        return match ($key) {
            'medication_reminders' => ['plans' => self::plans($payload['plans'] ?? [])],
            'medication_period' => [
                'enabled' => (bool)self::boolInt($payload['enabled'] ?? false, 'enabled'),
                'startDate' => self::date($payload['startDate'] ?? '', 'startDate'),
                'endDate' => self::date($payload['endDate'] ?? '', 'endDate'),
            ],
            // Os valores em falta caem no que o aparelho traz de fábrica, e não em erro: o
            // painel pede o payload por omissão antes de alguém escolher seja o que for, e
            // um por omissão que não passa na própria validação não é um por omissão.
            'early_dispense', 'child_lock' => [
                'enabled' => (bool)self::boolInt($payload['enabled'] ?? false, 'enabled'),
            ],
            // As gamas são as da especificação: quatro níveis de volume, em que 0 é o mais
            // alto e 3 é silêncio, e cinco toques a contar com o «nenhum».
            'alarm_volume' => ['volume' => self::zeroBasedRangeInt($payload['volume'] ?? 0, 0, 3, 'volume')],
            'alarm_ringtone' => ['ringtone' => self::zeroBasedRangeInt($payload['ringtone'] ?? 0, 0, 4, 'ringtone')],
            'do_not_disturb' => [
                'enabled' => (bool)self::boolInt($payload['enabled'] ?? false, 'enabled'),
                'startHour' => self::zeroBasedRangeInt($payload['startHour'] ?? 22, 0, 23, 'startHour'),
                'startMinute' => self::zeroBasedRangeInt($payload['startMinute'] ?? 0, 0, 59, 'startMinute'),
                'endHour' => self::zeroBasedRangeInt($payload['endHour'] ?? 7, 0, 23, 'endHour'),
                'endMinute' => self::zeroBasedRangeInt($payload['endMinute'] ?? 0, 0, 59, 'endMinute'),
            ],
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
     * @return list<array{hour: int, minute: int, enabled: bool}>
     */
    private static function plans(mixed $plans): array
    {
        if (!is_array($plans)) {
            throw new \InvalidArgumentException('plans must be a list');
        }
        if (count($plans) > self::ALARM_SLOTS) {
            throw new \InvalidArgumentException('the M228 has ' . self::ALARM_SLOTS . ' alarms');
        }

        $out = [];
        foreach (array_values($plans) as $index => $plan) {
            if (!is_array($plan)) {
                throw new \InvalidArgumentException('plans items must be objects');
            }
            $out[] = [
                'hour' => self::zeroBasedRangeInt($plan['hour'] ?? 0, 0, 23, "plans[{$index}].hour"),
                'minute' => self::zeroBasedRangeInt($plan['minute'] ?? 0, 0, 59, "plans[{$index}].minute"),
                'enabled' => (bool)self::boolInt($plan['enabled'] ?? true, "plans[{$index}].enabled"),
            ];
        }

        return $out;
    }
}
