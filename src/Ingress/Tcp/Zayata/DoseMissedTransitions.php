<?php

declare(strict_types=1);

namespace Hub\Ingress\Tcp\Zayata;

use Hub\State\ActiveAlarms;

/**
 * Deriva o `dose_missed` do dispensador: um por dose, pela primeira via que a dá por falhada — o
 * alarme a passar a `timed_out`, `retrieval_timed_out` ou `missed`, ou a toma com `result: "missed"`.
 */
final class DoseMissedTransitions
{
    private const MISSED_STATES = ['timed_out', 'retrieval_timed_out', 'missed'];

    /** Uma dose identificada pelo dia já não volta; sem dia, só o slot a separa da seguinte. */
    private const TTL_SECONDS = 2 * 86400;
    private const UNDATED_TTL_SECONDS = 12 * 3600;

    public function __construct(private readonly ActiveAlarms $alarms = new ActiveAlarms())
    {
    }

    /**
     * @param array<string, mixed> $event o envelope que acabou de sair, já com as horas do plano
     * @return array<string, mixed>|null o `dose_missed` a publicar a seguir, se esta dose ainda não o teve
     */
    public function observe(string $deviceKey, array $event): ?array
    {
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        $missed = match ($event['type'] ?? null) {
            'medication_alarm_change' => in_array($data['state'] ?? null, self::MISSED_STATES, true),
            'medication_intake' => ($data['result'] ?? null) === 'missed',
            default => false,
        };
        if (!$missed || !is_int($data['slot'] ?? null)) {
            return null;
        }

        $field = self::field($data);
        if ($this->alarms->get($deviceKey, $field) !== null) {
            return null;
        }
        $this->alarms->set($deviceKey, $field, '1', isset($data['doseDate']) ? self::TTL_SECONDS : self::UNDATED_TTL_SECONDS);

        return [
            'type' => 'dose_missed',
            'data' => array_intersect_key($data, ['slot' => true, 'scheduledAt' => true, 'doseDate' => true]),
        ] + $event;
    }

    /** @param array<string, mixed> $missed o `dose_missed` que não chegou a sair */
    public function forget(string $deviceKey, array $missed): void
    {
        $this->alarms->set($deviceKey, self::field($missed['data'] ?? []), null, 0);
    }

    /** @param array<string, mixed> $data */
    private static function field(array $data): string
    {
        return 'dose_missed:' . ($data['slot'] ?? '') . ':' . ($data['doseDate'] ?? 'undated');
    }
}
