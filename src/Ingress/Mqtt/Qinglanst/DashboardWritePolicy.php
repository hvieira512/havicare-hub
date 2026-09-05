<?php

namespace Hub\Ingress\Mqtt\Qinglanst;

final class DashboardWritePolicy
{
    /** @var array<string, int> */
    private array $lastSeenMs = [];
    /** @var array<string, int> */
    private array $lastTelemetryMs = [];
    /** @var array<string, int> */
    private array $lastRawMs = [];

    public function __construct(
        private readonly int $deviceSeenMinIntervalMs = 5000,
        private readonly int $positionHistorySampleMs = 1000,
        private readonly int $rawHistorySampleMs = 30000,
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

    /**
     * `$capability` é a chave da capacidade, não o tipo do envelope do fabricante.
     *
     * Só a posição é amostrada: chega uma vez por segundo e o histórico não precisa de
     * cada leitura. As outras passam sempre.
     */
    public function shouldStoreTelemetry(string $deviceKey, string $capability, int $nowMs): bool
    {
        if ($capability !== 'positions' || $this->positionHistorySampleMs <= 0) {
            $this->lastTelemetryMs[$deviceKey . '|' . $capability] = $nowMs;
            return true;
        }

        $key = $deviceKey . '|' . $capability;
        $last = $this->lastTelemetryMs[$key] ?? null;
        if ($last !== null && ($nowMs - $last) < $this->positionHistorySampleMs) {
            return false;
        }

        $this->lastTelemetryMs[$key] = $nowMs;
        return true;
    }

    /**
     * O raw vai para o histórico no máximo uma vez por janela, por dispositivo. Um radar
     * publica muitas mensagens por segundo; no MQTT saem todas, mas o histórico da dashboard
     * leva só uma amostra, para não se afogar nem somar escritas ao caminho quente.
     */
    public function shouldStoreRaw(string $deviceKey, int $nowMs): bool
    {
        if ($this->rawHistorySampleMs <= 0) {
            $this->lastRawMs[$deviceKey] = $nowMs;
            return true;
        }

        $last = $this->lastRawMs[$deviceKey] ?? null;
        if ($last !== null && ($nowMs - $last) < $this->rawHistorySampleMs) {
            return false;
        }

        $this->lastRawMs[$deviceKey] = $nowMs;
        return true;
    }
}
