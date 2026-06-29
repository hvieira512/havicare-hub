<?php

namespace Hub\Ingress\Mqtt\Qinglanst;

use Hub\Log\Logger;

final class IngestStats
{
    private int $windowStartedAtNs;
    private int $lastFlushAtNs;
    private int $messages = 0;
    private int $accepted = 0;
    private int $rejected = 0;
    private int $telemetryPublishes = 0;
    private int $eventPublishes = 0;
    /** @var array<string, int> */
    private array $rejectReasons = [];
    /** @var array<string, int> */
    private array $typeCounts = [];
    /** @var array<string, array{sum: int, max: int}> */
    private array $timings = [];

    public function __construct(
        private readonly string $topicFilter,
        private readonly int $flushIntervalSeconds = 30,
    ) {
        $now = hrtime(true);
        $this->windowStartedAtNs = $now;
        $this->lastFlushAtNs = $now;
    }

    /**
     * @param array<string, int> $timingsNs
     */
    public function recordAccepted(string $messageType, bool $publishedTelemetry, bool $publishedEvent, array $timingsNs): void
    {
        $this->messages++;
        $this->accepted++;
        $this->typeCounts[$messageType] = ($this->typeCounts[$messageType] ?? 0) + 1;
        if ($publishedTelemetry) {
            $this->telemetryPublishes++;
        }
        if ($publishedEvent) {
            $this->eventPublishes++;
        }
        $this->recordTimings($timingsNs);
        $this->maybeFlush();
    }

    /**
     * @param array<string, int> $timingsNs
     */
    public function recordRejected(string $reason, array $timingsNs = []): void
    {
        $this->messages++;
        $this->rejected++;
        $this->rejectReasons[$reason] = ($this->rejectReasons[$reason] ?? 0) + 1;
        $this->recordTimings($timingsNs);
        $this->maybeFlush();
    }

    public function flush(bool $force = false): void
    {
        if (!$force && $this->messages === 0) {
            return;
        }

        $now = hrtime(true);
        $elapsedNs = max(1, $now - $this->windowStartedAtNs);
        $messagesPerSecond = $this->messages / ($elapsedNs / 1_000_000_000);

        $context = [
            'topic_filter' => $this->topicFilter,
            'window_s' => round($elapsedNs / 1_000_000_000, 3),
            'messages' => $this->messages,
            'accepted' => $this->accepted,
            'rejected' => $this->rejected,
            'msg_per_s' => round($messagesPerSecond, 2),
            'telemetry_publishes' => $this->telemetryPublishes,
            'event_publishes' => $this->eventPublishes,
            'types' => $this->typeCounts,
            'reject_reasons' => $this->rejectReasons,
            'timings_ms' => $this->timingsSummaryMs(),
        ];

        Logger::channel('hub')->info('Qinglanst ingest stats', $context);
        $this->reset($now);
    }

    /**
     * @param array<string, int> $timingsNs
     */
    private function recordTimings(array $timingsNs): void
    {
        foreach ($timingsNs as $name => $durationNs) {
            if ($durationNs < 0) {
                continue;
            }
            if (!isset($this->timings[$name])) {
                $this->timings[$name] = ['sum' => 0, 'max' => 0];
            }
            $this->timings[$name]['sum'] += $durationNs;
            $this->timings[$name]['max'] = max($this->timings[$name]['max'], $durationNs);
        }
    }

    private function maybeFlush(): void
    {
        $now = hrtime(true);
        if (($now - $this->lastFlushAtNs) < ($this->flushIntervalSeconds * 1_000_000_000)) {
            return;
        }

        $this->flush(true);
    }

    /**
     * @return array<string, array{avg: float, max: float}>
     */
    private function timingsSummaryMs(): array
    {
        $summary = [];
        $divisor = max(1, $this->accepted + $this->rejected);
        foreach ($this->timings as $name => $data) {
            $summary[$name] = [
                'avg' => round(($data['sum'] / $divisor) / 1_000_000, 3),
                'max' => round($data['max'] / 1_000_000, 3),
            ];
        }

        return $summary;
    }

    private function reset(int $nowNs): void
    {
        $this->windowStartedAtNs = $nowNs;
        $this->lastFlushAtNs = $nowNs;
        $this->messages = 0;
        $this->accepted = 0;
        $this->rejected = 0;
        $this->telemetryPublishes = 0;
        $this->eventPublishes = 0;
        $this->rejectReasons = [];
        $this->typeCounts = [];
        $this->timings = [];
    }
}
