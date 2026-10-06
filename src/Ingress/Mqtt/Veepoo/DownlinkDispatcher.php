<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Veepoo;

use Hub\State\DeviceCommandLog;
use Hub\Device\HubMqttBridge;
use Hub\Device\PendingDownlink;
use Hub\Device\PendingDownlinkQueue;
use Hub\Log\Logger;

/**
 * Entrega às pulseiras o que está em fila, pelo canal de comandos do gateway, que tem a
 * sessão BLE, e fecha o ciclo quando ele confirma.
 */
final class DownlinkDispatcher
{
    /**
     * Espera de um comando entregue e não confirmado antes de se repetir, para o caso de a
     * entrega se ter perdido: o gateway ignora a mesma chave durante minutos.
     */
    private const RESEND_SECONDS = 30;

    /**
     * Quando cada comando em fila foi entregue pela última vez.
     *
     * @var array<string, int>
     */
    private array $sentAt = [];

    public function __construct(
        private readonly ?PendingDownlinkQueue $downlinks,
        private readonly HubMqttBridge $mqttBridge,
        private readonly ?DeviceCommandLog $deviceStore,
        private readonly string $topicFilter,
    ) {
    }

    public function dispatchPending(string $deviceKey, string $gatewayKey): void
    {
        if ($this->downlinks === null) {
            return;
        }

        $this->forgetExpiredSends();

        foreach ($this->downlinks->pendingFor($deviceKey) as $downlink) {
            $command = $downlink->command ?? [];
            $operation = self::operationOf($downlink);
            if ($operation === '' || !$this->dueForSending($downlink->dedupeKey)) {
                continue;
            }

            $this->mqttBridge->publishGatewayCommand($this->commandTopicFor($gatewayKey), [
                'deviceId' => $deviceKey,
                'operation' => $operation,
                // O nome da operação diz o que fazer, e o valor é que distingue ligar de desligar.
                'payload' => $command['payload'] ?? null,
                'dedupeKey' => $downlink->dedupeKey,
                'commandId' => $command['id'] ?? null,
                'expiresAt' => $downlink->expiresAt,
            ]);

            // Só depois de sair: uma publicação que rebenta não fica marcada como entregue.
            $this->markSent($downlink->dedupeKey);

            // Fica em fila: entregar não é executar. Sai em `resolvePending`, quando o gateway
            // confirma, ou pelo TTL da política.
            $this->deviceStore?->markLatestCommand($deviceKey, $operation, [
                'status' => 'waiting',
                'sentAt' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);

            Logger::channel('hub')->info(
                "Veepoo downlink {$operation} entregue ao gateway {$gatewayKey} para {$deviceKey}"
            );
        }
    }

    /** Tira da fila o comando que o gateway confirmou ter executado. */
    public function resolvePending(string $deviceKey, string $dedupeKey): void
    {
        if ($this->downlinks === null || $dedupeKey === '') {
            return;
        }

        foreach ($this->downlinks->pendingFor($deviceKey) as $downlink) {
            if ($downlink->dedupeKey !== $dedupeKey) {
                continue;
            }

            $this->downlinks->remove($downlink);
            // Confirmada, a chave sai do travão para a ordem seguinte sair na hora.
            unset($this->sentAt[$dedupeKey]);
            $operation = self::operationOf($downlink);
            if ($operation !== '') {
                $this->deviceStore?->markLatestCommand($deviceKey, $operation, [
                    'status' => 'acked',
                    'ackedAt' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
            }

            Logger::channel('hub')->info("Veepoo downlink {$dedupeKey} confirmado por {$deviceKey}");
            return;
        }
    }

    /** Fecha o pedido que a pulseira não consegue cumprir, e só o daquela operação. */
    public function failPending(string $deviceKey, string $operation, string $reason): void
    {
        if ($this->downlinks === null || $operation === '') {
            return;
        }

        foreach ($this->downlinks->pendingFor($deviceKey) as $downlink) {
            if (self::operationOf($downlink) !== $operation) {
                continue;
            }

            $this->downlinks->remove($downlink);
            unset($this->sentAt[$downlink->dedupeKey]);
            $this->deviceStore?->markLatestCommand($deviceKey, $operation, [
                'status' => 'failed',
                'error' => $reason,
                'failedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);

            Logger::channel('hub')->info(
                "Veepoo downlink {$operation} de {$deviceKey} encerrado por {$reason}"
            );
        }
    }

    /** O nome da operação: os bytes em fila, ou o `command` quando a ordem leva valor. */
    private static function operationOf(PendingDownlink $downlink): string
    {
        return (string)(($downlink->command['command'] ?? null) ?? $downlink->bytes);
    }

    /** Só pergunta; quem marca é o `markSent`. */
    private function dueForSending(string $dedupeKey): bool
    {
        return !isset($this->sentAt[$dedupeKey]);
    }

    private function markSent(string $dedupeKey): void
    {
        $this->sentAt[$dedupeKey] = time();
    }

    /** As chaves que já saíram da janela deixam de travar a entrega seguinte. */
    private function forgetExpiredSends(): void
    {
        $now = time();
        foreach ($this->sentAt as $key => $at) {
            if ($now - $at >= self::RESEND_SECONDS) {
                unset($this->sentAt[$key]);
            }
        }
    }

    /** `.../gw/{mac}/raw` para o que sobe, `.../gw/{mac}/cmd` para o que desce. */
    private function commandTopicFor(string $gatewayKey): string
    {
        $base = preg_replace('#/\+/raw$#', '', trim($this->topicFilter, '/')) ?? '';

        return $base . '/' . $gatewayKey . '/cmd';
    }
}
