<?php

declare(strict_types=1);

namespace Hub\Domain\Capability;

/**
 * A gravidade de um acontecimento: `alarm` é perigo e pede alguém já, `alert` pede atenção sem
 * urgência, `info` é um facto. Os eventos de ligação (`device.*`) não levam nenhuma.
 */
final class EventSeverity
{
    public const ALARM = 'alarm';
    public const ALERT = 'alert';
    public const INFO = 'info';

    private const BY_TYPE = [
        'help_call' => self::ALARM,
        'proximity_alarm' => self::ALARM,
        'change_required' => self::ALARM,
        // O fabricante do radar só manda alarmar logo os vitais fracos, e não a apneia (Cloud2cloud V2.2.3, p. 11 e 39).
        'weak_vital_signs' => self::ALARM,
        'apnea' => self::ALERT,
        'heart_rate_abnormal' => self::ALERT,
        'breath_rate_high' => self::ALERT,
        'breath_rate_low' => self::ALERT,
        'device_removed' => self::ALERT,
        'check_required' => self::ALERT,
        'device_fault' => self::ALERT,
        'storage_environment' => self::ALERT,
        'dose_missed' => self::ALERT,
        'reset' => self::INFO,
        'device_state' => self::INFO,
        'low_battery' => self::INFO,
        'medication_alarm_change' => self::INFO,
        'medication_intake' => self::INFO,
    ];

    /** Os limites da frequência cardíaca abaixo e acima dos quais o alerta passa a alarme. */
    private const HEART_RATE_CRITICAL_LOW_BPM = 20;
    private const HEART_RATE_CRITICAL_HIGH_BPM = 160;

    /** @param array<string, mixed> $data */
    public static function of(string $type, array $data): ?string
    {
        $bpm = is_numeric($data['bpm'] ?? null) ? (int)$data['bpm'] : null;

        return match ($type) {
            'fall' => ($data['confirmed'] ?? true) === false ? self::ALERT : self::ALARM,
            'heart_rate_high' => $bpm !== null && $bpm > self::HEART_RATE_CRITICAL_HIGH_BPM ? self::ALARM : self::ALERT,
            'heart_rate_low' => $bpm !== null && $bpm < self::HEART_RATE_CRITICAL_LOW_BPM ? self::ALARM : self::ALERT,
            'zone_exit' => ($data['zone'] ?? null) === 'geofence' ? self::ALERT : self::INFO,
            'zone_entry' => self::INFO,
            default => self::BY_TYPE[$type] ?? null,
        };
    }

    /**
     * O evento com a `severity` logo a seguir ao `type`, onde quem lê a mensagem a procura.
     *
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public static function stamp(array $event): array
    {
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        $severity = self::of((string)($event['type'] ?? ''), $data);

        return $severity === null ? $event : self::put($event, $severity);
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private static function put(array $event, string $severity): array
    {
        unset($event['severity']);

        return array_slice($event, 0, 1, true) + ['severity' => $severity] + array_slice($event, 1, null, true);
    }
}
