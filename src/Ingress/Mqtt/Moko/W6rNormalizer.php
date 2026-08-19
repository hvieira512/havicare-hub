<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Moko;

/**
 * Turns a decoded W6R advertisement into the hub's generic shapes.
 *
 * A press is reported as a per-mode counter rather than an event, so the caller
 * supplies the previous count and this only emits a help_call when the counter
 * actually moved.
 */
final class W6rNormalizer
{
    /** Modes that represent someone pressing the button. */
    private const HELP_CALL_MODES = ['single', 'double', 'long'];

    /**
     * @param array<string, mixed> $decoded
     * @param array<string, mixed> $device
     * @param int|null $previousTriggerCount null while establishing the baseline
     * @return array{telemetry: array<string, array<string, mixed>>, events: list<array<string, mixed>>}
     */
    public function normalize(
        array $decoded,
        array $device,
        string $gatewayId,
        ?int $previousTriggerCount = null,
    ): array {
        $common = [
            'schemaVersion' => 2,
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'device' => $this->device($device),
            'source' => array_filter([
                'protocol' => 'moko-w6r',
                'nativeType' => 'manufacturer_data',
                'gatewayId' => $gatewayId,
                'rssiDbm' => $decoded['rssiDbm'] ?? null,
            ], static fn(mixed $value): bool => $value !== null && $value !== ''),
        ];

        return [
            'telemetry' => $this->telemetry($decoded['info'] ?? null, $common),
            'events' => $this->events($decoded['alarm'] ?? null, $previousTriggerCount, $common),
        ];
    }

    /**
     * @param array<string, mixed>|null $info
     * @param array<string, mixed> $common
     * @return array<string, array<string, mixed>>
     */
    private function telemetry(?array $info, array $common): array
    {
        if ($info === null) {
            return [];
        }

        $telemetry = [];

        if (isset($info['batteryPercent'])) {
            $telemetry['battery'] = ['type' => 'battery', 'data' => ['percent' => (int)$info['batteryPercent']]] + $common;
        } elseif (isset($info['batteryVoltageMv'])) {
            $telemetry['battery'] = ['type' => 'battery', 'data' => ['voltageMv' => (int)$info['batteryVoltageMv']]] + $common;
        }

        // The accelerometer in `accelerationMg` is deliberately not normalized.
        // It arrives with every advertisement, so it published at roughly one
        // message every two seconds -- 35k a day per bracelet -- and on a worn
        // bracelet at rest it carries nothing: measured over a minute it held
        // four distinct magnitudes, 1044/1048/1052/1056 mg, which is gravity
        // plus the sensor's 4 mg resolution. The publish throttle could not
        // suppress it either, because that noise changes the payload
        // fingerprint on almost every reading.
        //
        // Nothing consumed it. Proximity alarms are decided from RSSI alone
        // (rssiMaxDbm, rssiMedianDbm, samples), and the one use that movement
        // would have served -- telling a worn bracelet from one left on a table
        // near a door -- does not arise, because the bracelet is worn and the
        // alarm is for a gateway on a gate.

        return $telemetry;
    }

    /**
     * @param array<string, mixed>|null $alarm
     * @param array<string, mixed> $common
     * @return list<array<string, mixed>>
     */
    private function events(?array $alarm, ?int $previousTriggerCount, array $common): array
    {
        if ($alarm === null || !in_array($alarm['pressMode'], self::HELP_CALL_MODES, true)) {
            return [];
        }

        // The counter is broadcast continuously, so without a previous value
        // there is nothing to compare against: the first sighting of a device
        // establishes the baseline instead of replaying its press history.
        $triggerCount = (int)$alarm['triggerCount'];
        if ($previousTriggerCount === null || $triggerCount === $previousTriggerCount) {
            return [];
        }

        return [[
            'type' => 'help_call',
            'data' => [
                'pressType' => $alarm['pressMode'],
                'triggerCount' => $triggerCount,
                // A device that restarts resets its counters, so a decrease is
                // still one press rather than a negative delta.
                'presses' => $triggerCount > $previousTriggerCount
                    ? $triggerCount - $previousTriggerCount
                    : 1,
            ],
        ] + $common];
    }

    /** @param array<string, mixed> $device @return array<string, string> */
    private function device(array $device): array
    {
        return array_filter([
            'id' => (string)$device['imei'],
            'supplier' => (string)($device['supplier'] ?? ''),
            'model' => (string)($device['model'] ?? ''),
            'commercialName' => (string)($device['commercialName'] ?? ''),
        ], static fn(string $value): bool => $value !== '');
    }
}
