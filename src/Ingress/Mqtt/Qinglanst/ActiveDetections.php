<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Qinglanst;

use Hub\Domain\Liveness;
use Hub\State\ActiveAlarms;

/**
 * O radar repete a postura e os vitais em cada trama: uma detecção só sai quando não estava
 * ativa na trama anterior do mesmo tipo, e volta a poder sair depois de desaparecer.
 */
final class ActiveDetections
{
    /** Os valores medidos mudam a cada trama e não fazem de uma detecção outra. */
    private const MEASURED = ['bpm' => true, 'breathsPerMinute' => true];

    public function __construct(private readonly ActiveAlarms $alarms = new ActiveAlarms())
    {
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    public function fresh(string $deviceKey, string $messageType, array $events): array
    {
        $previous = json_decode($this->alarms->get($deviceKey, $messageType) ?? '[]', true);
        $previous = is_array($previous) ? $previous : [];
        $current = [];
        $fresh = [];
        foreach ($events as $event) {
            $data = is_array($event['data'] ?? null) ? $event['data'] : [];
            $signature = (string)($event['type'] ?? '') . json_encode(array_diff_key($data, self::MEASURED));
            $current[] = $signature;
            if (!in_array($signature, $previous, true)) {
                $fresh[] = $event;
            }
        }
        if ($current === [] && $previous === []) {
            return $fresh;
        }
        // Cada trama renova o prazo: só o radar calado até se dar por desligado volta a anunciar.
        $this->alarms->set(
            $deviceKey,
            $messageType,
            $current === [] ? null : json_encode($current, JSON_THROW_ON_ERROR),
            Liveness::idleTimeout('qinglanst-radar') ?? 0,
        );

        return $fresh;
    }
}
