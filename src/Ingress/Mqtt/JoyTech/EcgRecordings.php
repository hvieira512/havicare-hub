<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\JoyTech;

/**
 * Os ECG em curso de cada tensiómetro: o `0x85` da contagem abre, cada `0x87` junta amostras e o
 * `0x86` fecha. O aparelho repete o `0x85` e o `0x86` até ter resposta, e só a primeira conta.
 */
final class EcgRecordings
{
    /**
     * Um exame são 30 s a 250 Hz, 7500 amostras; com menos do que isto foi interrompido. Conta-se o
     * que o aparelho mandou, e não quando chegou: um atraso a caminho do hub não é um buraco.
     */
    private const MIN_SAMPLES = 7125;

    /** O `0x85` repete-se 4 vezes, de segundo a segundo, e o `0x86` de 2 em 2 s. */
    private const REPEAT_SECONDS = 5.0;

    /** @var array<string, array{samples: list<int>}> */
    private array $open = [];

    /** @var array<string, float> */
    private array $answeredAt = [];

    /** @return bool se é o primeiro `0x85` desta contagem, e tem de ter resposta */
    public function countdown(string $deviceKey, float $now): bool
    {
        if (!$this->first($deviceKey . ':85', $now)) {
            return false;
        }
        $this->open[$deviceKey] = ['samples' => []];

        return true;
    }

    /** @param string $data as amostras em int16 com sinal, e no fim dois bytes de estado */
    public function samples(string $deviceKey, string $data): void
    {
        if (!isset($this->open[$deviceKey])) {
            return;
        }
        $count = intdiv(strlen($data) - 2, 2);
        foreach ($count > 0 ? array_values(unpack("n{$count}", $data) ?: []) : [] as $value) {
            $this->open[$deviceKey]['samples'][] = $value >= 0x8000 ? $value - 0x10000 : $value;
        }
    }

    /**
     * O `0x86`: se é o primeiro, e o traçado se estiver inteiro.
     *
     * @return array{answer: bool, samples: list<int>|null, count?: int}
     */
    public function end(string $deviceKey, float $now): array
    {
        if (!$this->first($deviceKey . ':86', $now)) {
            return ['answer' => false, 'samples' => null];
        }
        $recording = $this->open[$deviceKey] ?? null;
        unset($this->open[$deviceKey]);
        $complete = $recording !== null && count($recording['samples']) >= self::MIN_SAMPLES;

        return ['answer' => true, 'samples' => $complete ? $recording['samples'] : null, 'count' => count($recording['samples'] ?? [])];
    }

    public function drop(string $deviceKey): void
    {
        unset($this->open[$deviceKey]);
    }

    private function first(string $key, float $now): bool
    {
        if ($now - ($this->answeredAt[$key] ?? -INF) < self::REPEAT_SECONDS) {
            return false;
        }
        $this->answeredAt[$key] = $now;

        return true;
    }
}
