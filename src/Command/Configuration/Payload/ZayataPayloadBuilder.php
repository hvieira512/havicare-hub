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
            'sound_profile' => [
                // Cinco níveis de volume e cinco toques, como o aparelho os numera.
                'volume' => self::zeroBasedRangeInt($payload['volume'] ?? 2, 0, 4, 'volume'),
                'ringtone' => self::zeroBasedRangeInt($payload['ringtone'] ?? 0, 0, 4, 'ringtone'),
            ],
            'do_not_disturb' => [
                'enabled' => (bool)self::boolInt($payload['enabled'] ?? false, 'enabled'),
                'startHour' => self::zeroBasedRangeInt($payload['startHour'] ?? 22, 0, 23, 'startHour'),
                'startMinute' => self::zeroBasedRangeInt($payload['startMinute'] ?? 0, 0, 59, 'startMinute'),
                'endHour' => self::zeroBasedRangeInt($payload['endHour'] ?? 7, 0, 23, 'endHour'),
                'endMinute' => self::zeroBasedRangeInt($payload['endMinute'] ?? 0, 0, 59, 'endMinute'),
            ],
            'language_timezone' => [
                'language' => self::zeroBasedRangeInt($payload['language'] ?? 0, 0, 20, 'language'),
                // Em minutos, e com sinal: Lisboa no inverno é 0, no verão 60, e os fusos a
                // oeste são negativos. A gama é a dos fusos que existem.
                'timezoneMinutes' => self::zeroBasedRangeInt($payload['timezoneMinutes'] ?? 0, -720, 840, 'timezoneMinutes'),
            ],
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
