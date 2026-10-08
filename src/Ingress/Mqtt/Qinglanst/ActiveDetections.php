<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Qinglanst;

/**
 * O radar repete a postura e os vitais em cada trama: uma detecção só sai quando não estava
 * ativa na trama anterior do mesmo tipo, e volta a poder sair depois de desaparecer.
 */
final class ActiveDetections
{
    /** Os valores medidos mudam a cada trama e não fazem de uma detecção outra. */
    private const MEASURED = ['bpm' => true, 'breathsPerMinute' => true];

    // ponytail: em memória, por processo; um reinício volta a anunciar o que estiver ativo.
    /** @var array<string, array<string, true>> */
    private array $active = [];

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    public function fresh(string $deviceKey, string $messageType, array $events): array
    {
        $key = $deviceKey . '|' . $messageType;
        $previous = $this->active[$key] ?? [];
        $current = [];
        $fresh = [];
        foreach ($events as $event) {
            $data = is_array($event['data'] ?? null) ? $event['data'] : [];
            $signature = (string)($event['type'] ?? '') . json_encode(array_diff_key($data, self::MEASURED));
            $current[$signature] = true;
            if (!isset($previous[$signature])) {
                $fresh[] = $event;
            }
        }
        $this->active[$key] = $current;

        return $fresh;
    }
}
