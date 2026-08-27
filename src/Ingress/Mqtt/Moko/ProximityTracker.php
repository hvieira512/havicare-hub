<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Moko;

/**
 * Uma janela curta de leituras de sinal por par (dispositivo, gateway).
 *
 * O hub reporta o sinal; o cliente decide o que ele significa. O que o hub lhe deve é uma
 * série sem buracos invisíveis e resumo bastante para um consumidor simples não ter de
 * construir um motor de janelas próprio.
 *
 * Três estatísticas e não uma, porque uma só não serve as duas perguntas que uma porta faz.
 * Medido numa pulseira real a 0.67 amostras/s:
 *
 *  - Passar a andar por um gateway são uma ou duas leituras, e uma mediana não se mexe com
 *    isso -- precisa de umas três. O máximo é que apanha a passagem.
 *  - O ruído é assimétrico: num aparelho imóvel as leituras ficaram 5 dB acima da mediana e
 *    9 dB abaixo. Corpos e paredes atenuam, quase nada amplifica, por isso uma leitura forte
 *    é de confiança e uma fraca não é. A mediana é que julga presença sustentada e decide
 *    que alguma coisa saiu.
 *
 * A janela é pequena de propósito e vive em memória: existe para descrever os últimos
 * segundos, e depois de um reinício reenche-se em `windowSeconds`. O registo durável do
 * último avistamento, para a dashboard, vive no store da dashboard.
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
     * Os pares que se calaram, esquecidos à medida que são reportados.
     *
     * A ausência não se empurra por MQTT: um cliente que não recebe nada não tem a que
     * reagir, e por isso é o hub que tem de dar por ela. Reportado uma vez e largado, o que
     * também quer dizer que um par que reapareça começa uma janela nova.
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
