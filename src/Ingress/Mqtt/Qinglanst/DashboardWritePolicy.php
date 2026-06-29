<?php

namespace Hub\Ingress\Mqtt\Qinglanst;

final class DashboardWritePolicy
{
    /** @var array<string, int> */
    private array $lastSeenMs = [];
    /** @var array<string, int> */
    private array $lastTelemetryMs = [];

    public function __construct(
        private readonly int $deviceSeenMinIntervalMs = 5000,
        private readonly int $positionHistorySampleMs = 1000,
    ) {
    }

    public function shouldUpdateSeen(string $deviceKey, int $nowMs): bool
    {
        if ($this->deviceSeenMinIntervalMs <= 0) {
            $this->lastSeenMs[$deviceKey] = $nowMs;
            return true;
        }

        $last = $this->lastSeenMs[$deviceKey] ?? null;
        if ($last !== null && ($nowMs - $last) < $this->deviceSeenMinIntervalMs) {
            return false;
        }

        $this->lastSeenMs[$deviceKey] = $nowMs;
        return true;
    }

    public function shouldStoreTelemetry(string $deviceKey, string $telemetryType, int $nowMs): bool
    {
        if ($telemetryType !== 'radar.position' || $this->positionHistorySampleMs <= 0) {
            $this->lastTelemetryMs[$deviceKey . '|' . $telemetryType] = $nowMs;
            return true;
        }

        $key = $deviceKey . '|' . $telemetryType;
        $last = $this->lastTelemetryMs[$key] ?? null;
        if ($last !== null && ($nowMs - $last) < $this->positionHistorySampleMs) {
            return false;
        }

        $this->lastTelemetryMs[$key] = $nowMs;
        return true;
    }
}
