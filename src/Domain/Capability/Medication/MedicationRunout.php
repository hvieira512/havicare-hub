<?php

declare(strict_types=1);

namespace Hub\Domain\Capability\Medication;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Quando sai a última dose carregada no dispensador: percorrem-se os alarmes do plano a partir de
 * agora, na hora local do aparelho. Dividir as doses pelos alarmes só bate certo no início do dia.
 */
final class MedicationRunout
{
    /** As configurações de que o cálculo precisa, pela chave do contrato. */
    public const CONFIGURATION_KEYS = ['medication_reminders', 'medication_period', 'time_zone'];

    /** Vinte e oito compartimentos a uma dose por dia dão 28; um período longe no futuro, mais. */
    private const SEARCH_DAYS = 400;

    /**
     * @param array<string, mixed> $configurations o valor efetivo de cada chave de `CONFIGURATION_KEYS`
     */
    public static function at(int $doses, array $configurations, DateTimeImmutable $now): ?string
    {
        $times = self::times($configurations['medication_reminders'] ?? null);
        if ($doses < 1 || $times === []) {
            return null;
        }

        $zone = self::zone($configurations['time_zone']['timeZone'] ?? 0);
        $now = $now->setTimezone($zone);
        $period = $configurations['medication_period'] ?? null;
        $bounded = is_array($period) && ($period['enabled'] ?? false) === true;
        $from = $bounded ? self::date($period['startDate'] ?? '', '00:00:00', $zone) : null;
        $until = $bounded ? self::date($period['endDate'] ?? '', '23:59:59', $zone) : null;

        $today = $now->setTime(0, 0);
        for ($day = 0; $day < self::SEARCH_DAYS; $day++) {
            $date = $today->modify("+{$day} days");
            foreach ($times as [$hour, $minute]) {
                $when = $date->setTime($hour, $minute);
                if ($when <= $now || ($from !== null && $when < $from) || ($until !== null && $when > $until)) {
                    continue;
                }
                if (--$doses === 0) {
                    return $when->format('Y-m-d\TH:i:sP');
                }
            }
        }

        return null;
    }

    /** @return list<array{int, int}> as horas ligadas, por ordem */
    private static function times(mixed $reminders): array
    {
        if (!is_array($reminders)) {
            return [];
        }

        $times = [];
        foreach ((new ZayataMedicationRemindersHandler())->fromNative($reminders)['plans'] as $plan) {
            if (($plan['enabled'] ?? true) === false) {
                continue;
            }
            foreach ((array)($plan['times'] ?? []) as $entry) {
                if (!is_array($entry) || !MedicationPlanShape::enabled($entry['enabled'] ?? true)) {
                    continue;
                }
                [$hour, $minute] = array_pad(explode(':', (string)($entry['time'] ?? '')), 2, '0');
                $times[] = [(int)$hour, (int)$minute];
            }
        }
        sort($times);

        return $times;
    }

    /**
     * O fuso do aparelho vem em HHMM, como a TAG `0x1015`, ou com nome, que segue a mudança de
     * hora; sem ele, o relógio foi acertado em UTC.
     */
    public static function zone(mixed $hhmm): DateTimeZone
    {
        if (is_string($hhmm) && !is_numeric($hhmm)) {
            return new DateTimeZone($hhmm);
        }
        $minutes = intdiv((int)$hhmm, 100) * 60 + (int)$hhmm % 100;

        return new DateTimeZone(sprintf('%s%02d:%02d', $minutes < 0 ? '-' : '+', intdiv(abs($minutes), 60), abs($minutes) % 60));
    }

    private static function date(mixed $date, string $time, DateTimeZone $zone): ?DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string)$date . ' ' . $time, $zone);

        return $parsed === false ? null : $parsed;
    }
}
