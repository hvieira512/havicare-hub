<?php

declare(strict_types=1);

namespace Hub\Domain\Capability\Medication;

use DateTimeImmutable;
use DateTimeZone;

/**
 * As horas dos eventos de toma do dispensador em UTC. O aparelho conta-as no relógio dele, e só o
 * fuso confirmado as situa: sem ele, as horas ficam de fora em vez de passarem por UTC.
 */
final class MedicationOccurrence
{
    private const UTC = 'Y-m-d\TH:i:s\Z';

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $configurations o valor confirmado de cada chave de `MedicationRunout::CONFIGURATION_KEYS`
     * @return array<string, mixed>
     */
    public static function intake(array $data, array $configurations): array
    {
        $zone = self::zone($configurations);
        foreach (['scheduledAt', 'takenAt'] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $local = $zone === null ? false : DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', (string)$data[$field], $zone);
            if ($local === false) {
                unset($data[$field]);
                continue;
            }
            // O dia da dose é o do aparelho, o mesmo que a mudança do alarme leva.
            if ($field === 'scheduledAt') {
                $data['doseDate'] = $local->format('Y-m-d');
            }
            $data[$field] = $local->setTimezone(new DateTimeZone('UTC'))->format(self::UTC);
        }

        return $data;
    }

    /**
     * A dose a que o estado se refere é a ocorrência do alarme mais próxima do momento da mudança:
     * a toma antecipada vem antes da hora, e a falhada depois.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $configurations
     * @return array<string, mixed>
     */
    public static function alarmChange(array $data, array $configurations, DateTimeImmutable $at): array
    {
        $zone = self::zone($configurations);
        $time = self::slotTime($configurations['medication_reminders'] ?? null, (int)($data['slot'] ?? 0));
        if ($zone === null || $time === null) {
            return $data;
        }

        $at = $at->setTimezone($zone);
        $nearest = null;
        foreach (['-1 day', '+0 days', '+1 day'] as $shift) {
            $candidate = $at->modify($shift)->setTime($time[0], $time[1]);
            if ($nearest === null || abs($candidate->getTimestamp() - $at->getTimestamp()) < abs($nearest->getTimestamp() - $at->getTimestamp())) {
                $nearest = $candidate;
            }
        }

        return $data + [
            'scheduledAt' => $nearest->setTimezone(new DateTimeZone('UTC'))->format(self::UTC),
            'doseDate' => $nearest->format('Y-m-d'),
        ];
    }

    /** @param array<string, mixed> $configurations */
    private static function zone(array $configurations): ?DateTimeZone
    {
        $zone = $configurations['time_zone']['timeZone'] ?? null;

        return $zone === null ? null : MedicationRunout::zone($zone);
    }

    /** @return array{int, int}|null a hora do alarme ligado nesse slot */
    private static function slotTime(mixed $reminders, int $slot): ?array
    {
        if (!is_array($reminders)) {
            return null;
        }

        foreach ((new PillDispenserMedicationRemindersHandler())->fromNative($reminders)['plans'] as $plan) {
            foreach ((array)($plan['times'] ?? []) as $entry) {
                if (is_array($entry) && (int)($entry['slot'] ?? 0) === $slot && MedicationPlanShape::enabled($entry['enabled'] ?? true)) {
                    [$hour, $minute] = array_pad(explode(':', (string)($entry['time'] ?? '')), 2, '0');

                    return [(int)$hour, (int)$minute];
                }
            }
        }

        return null;
    }
}
