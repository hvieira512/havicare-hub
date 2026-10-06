<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Moko;

/**
 * Uma janela curta, em memória, de leituras de sinal por par (dispositivo, gateway). Três
 * estatísticas: o ruído quase só atenua, e uma passagem só se apanha pelo máximo.
 */
final class ProximityTracker
{
    /** @var array<string, list<array{at: float, rssiDbm: int}>> */
    private array $windows = [];

    public function __construct(
        private readonly int $windowSeconds = 5,
        private readonly int $maxSamples = 10,
        private readonly int $stalenessSeconds = 30,
    ) {
    }

    /**
     * Acrescenta uma leitura e descreve a janela em que ela cai.
     *
     * @return array{state: string, rssiDbm: int, rssiMaxDbm: int, rssiMedianDbm: int, rssiMinDbm: int, samples: int, windowSeconds: int}
     */
    public function record(string $deviceKey, string $gatewayKey, int $rssiDbm, float $now): array
    {
        $key = $this->pairKey($deviceKey, $gatewayKey);
        $window = $this->windows[$key] ?? [];
        $window[] = ['at' => $now, 'rssiDbm' => $rssiDbm];
        $window = array_values(array_filter(
            $window,
            fn (array $sample): bool => $now - $sample['at'] <= $this->windowSeconds,
        ));
        if (count($window) > $this->maxSamples) {
            $window = array_slice($window, -$this->maxSamples);
        }
        $this->windows[$key] = $window;

        $readings = array_column($window, 'rssiDbm');
        sort($readings);
        $middle = intdiv(count($readings), 2);

        return [
            'state' => 'measured',
            'rssiDbm' => $rssiDbm,
            'rssiMaxDbm' => $readings[count($readings) - 1],
            // Com contagem par fica a menor das duas do meio, para ser um valor que a rádio viu.
            'rssiMedianDbm' => $readings[count($readings) % 2 === 1 ? $middle : $middle - 1],
            'rssiMinDbm' => $readings[0],
            'samples' => count($readings),
            'windowSeconds' => $this->windowSeconds,
        ];
    }

    /**
     * Os pares que se calaram, esquecidos ao serem reportados; um que reapareça começa janela nova.
     *
     * @return list<array{deviceKey: string, gatewayKey: string}>
     */
    public function takeStale(float $now): array
    {
        $stale = [];
        foreach ($this->windows as $key => $window) {
            $last = $window === [] ? 0.0 : $window[count($window) - 1]['at'];
            if ($now - $last < $this->stalenessSeconds) {
                continue;
            }
            [$deviceKey, $gatewayKey] = explode('|', $key, 2);
            $stale[] = ['deviceKey' => $deviceKey, 'gatewayKey' => $gatewayKey];
            unset($this->windows[$key]);
        }

        return $stale;
    }

    private function pairKey(string $deviceKey, string $gatewayKey): string
    {
        return $deviceKey . '|' . $gatewayKey;
    }
}
