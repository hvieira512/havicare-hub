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
        'apnea' => self::ALARM,
        'change_required' => self::ALARM,
        'heart_rate_abnormal' => self::ALERT,
        'breath_rate_high' => self::ALERT,
        'breath_rate_low' => self::ALERT,
        'weak_vital_signs' => self::ALERT,
        'device_removed' => self::ALERT,
        'low_battery' => self::ALERT,
        'check_required' => self::ALERT,
        'device_fault' => self::ALERT,
        'storage_environment' => self::ALERT,
        'device_state' => self::ALERT,
        'reset' => self::INFO,
    ];

    /** Os limites da frequência cardíaca abaixo e acima dos quais o alerta passa a alarme. */
    private const HEART_RATE_CRITICAL_LOW_BPM = 20;
    private const HEART_RATE_CRITICAL_HIGH_BPM = 160;

    private const MISSED_DOSE_STATES = ['missed', 'timed_out', 'retrieval_timed_out'];
    private const ABNORMAL_INTAKE_RESULTS = ['abnormal', 'missed'];

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
            'medication_alarm_change' => in_array($data['state'] ?? null, self::MISSED_DOSE_STATES, true) ? self::ALERT : self::INFO,
            'medication_intake' => in_array($data['result'] ?? null, self::ABNORMAL_INTAKE_RESULTS, true) ? self::ALERT : self::INFO,
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
        if ($severity === null) {
            return $event;
        }

        unset($event['severity']);

        return array_slice($event, 0, 1, true) + ['severity' => $severity] + array_slice($event, 1, null, true);
    }
}
