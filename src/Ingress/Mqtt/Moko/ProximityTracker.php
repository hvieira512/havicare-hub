<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Moko;

/**
 * A short window of signal readings per (device, gateway) pair.
 *
 * The hub reports the signal; the client decides what it means. What the hub owes
 * the client is a series with no invisible gaps and enough summary that a simple
 * consumer does not have to build a windowing engine of its own.
 *
 * Three statistics rather than one, because a single one cannot serve both
 * questions a door asks. Measured on a real bracelet at 0.67 samples/s:
 *
 *  - A brisk walk past a gateway is only one or two readings, so a median never
 *    moves for it -- it needs about three. Use the maximum to catch a pass.
 *  - Noise is asymmetric: on a motionless device readings sat 5 dB above the
 *    median and 9 dB below it. Bodies and walls attenuate, almost nothing
 *    amplifies, so a strong reading is trustworthy where a weak one is not. Use
 *    the median to judge sustained presence and to decide something has left.
 *
 * The window is intentionally small and kept in memory: it exists to describe the
 * last few seconds, and after a restart it refills within `windowSeconds`. The
 * durable last-sighting record for the dashboard lives in the dashboard store.
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
     * Add a reading and describe the window it lands in.
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
            // Even counts take the lower of the two middle readings rather than
            // averaging them, so the value is always one the radio really saw.
            'rssiMedianDbm' => $readings[count($readings) % 2 === 1 ? $middle : $middle - 1],
            'rssiMinDbm' => $readings[0],
            'samples' => count($readings),
            'windowSeconds' => $this->windowSeconds,
        ];
    }

    /**
     * Pairs that have gone quiet, forgotten as they are reported.
     *
     * Absence cannot be pushed over MQTT: a client that receives nothing has
     * nothing to react to, so the hub has to notice on its own. Reported once and
     * then dropped, which also means a pair reappearing starts a fresh window.
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
