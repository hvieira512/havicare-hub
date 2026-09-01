<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Moko;

/**
 * Uma janela curta de leituras de sinal por par (dispositivo, gateway). O hub reporta o
 * sinal; o cliente decide o que ele significa -- ver `docs/05-gateways-ble.md` §5.
 *
 * Três estatísticas e não uma, porque uma passagem a andar são uma ou duas leituras e a
 * mediana não se mexe com isso -- é o máximo que a apanha. O ruído é assimétrico: corpos e
 * paredes atenuam e quase nada amplifica, por isso uma leitura forte é de confiança e uma
 * fraca não.
 *
 * A janela vive em memória e reenche-se em `windowSeconds` depois de um reinício. O registo
 * durável do último avistamento fica no store da dashboard.
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
            // Com contagem par fica a menor das duas leituras do meio em vez da média, para
            // o valor ser sempre um que a rádio viu de facto.
            'rssiMedianDbm' => $readings[count($readings) % 2 === 1 ? $middle : $middle - 1],
            'rssiMinDbm' => $readings[0],
            'samples' => count($readings),
            'windowSeconds' => $this->windowSeconds,
        ];
    }

    /**
     * Os pares que se calaram, esquecidos à medida que são reportados: um cliente que não
     * recebe nada não tem a que reagir. Um par que reapareça começa uma janela nova.
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
