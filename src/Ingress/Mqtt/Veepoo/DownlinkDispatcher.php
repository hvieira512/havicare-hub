<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Veepoo;

use Hub\Command\Downlink\PendingDownlink;
use Hub\Command\Downlink\RedisPendingDownlinkQueue;
use Hub\Device\HubMqttBridge;
use Hub\Ingress\Mqtt\Gateway\GattDownlinks;
use Hub\Log\Logger;
use Hub\State\DeviceCommandLog;

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
     * Quando cada comando em fila foi entregue pela última vez, por aparelho e chave.
     *
     * @var array<string, int>
     */
    private array $sentAt = [];

    public function __construct(
        private readonly ?RedisPendingDownlinkQueue $downlinks,
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
            $operation = GattDownlinks::operationOf($downlink);
            if ($operation === '' || !$this->dueForSending($deviceKey, $downlink->dedupeKey)) {
                continue;
            }

            $this->mqttBridge->publishGatewayCommand(GattDownlinks::commandTopic($this->topicFilter, $gatewayKey), [
                'deviceId' => $deviceKey,
                'operation' => $operation,
                // O nome da operação diz o que fazer, e o valor é que distingue ligar de desligar.
                'payload' => $command['payload'] ?? null,
                'dedupeKey' => $downlink->dedupeKey,
                'commandId' => $command['id'] ?? null,
                'expiresAt' => $downlink->expiresAt,
            ]);

            // Só depois de sair: uma publicação que rebenta não fica marcada como entregue.
            $this->markSent($deviceKey, $downlink->dedupeKey);

            // Fica em fila: entregar não é executar. Sai em `resolvePending`, quando o gateway
            // confirma, ou pelo TTL da política.
            $this->mark($deviceKey, $downlink, [
                'status' => 'waiting',
                'sentAt' => gmdate('Y-m-d\TH:i:s\Z'),
                'announceSent' => true,
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
            unset($this->sentAt[$deviceKey . '|' . $dedupeKey]);
            $this->mark($deviceKey, $downlink, [
                'status' => 'acked',
                'ackedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);

            Logger::channel('hub')->info("Veepoo downlink {$dedupeKey} confirmado por {$deviceKey}");
            return;
        }
    }

    /**
     * Fecha o pedido que a pulseira não consegue cumprir, e só o daquela operação; com a chave, só
     * esse, porque vibrar e parar são a mesma operação.
     */
    public function failPending(string $deviceKey, string $operation, string $reason, string $dedupeKey = ''): void
    {
        if ($this->downlinks === null || $operation === '') {
            return;
        }

        foreach ($this->downlinks->pendingFor($deviceKey) as $downlink) {
            if (GattDownlinks::operationOf($downlink) !== $operation || ($dedupeKey !== '' && $downlink->dedupeKey !== $dedupeKey)) {
                continue;
            }

            $this->downlinks->remove($downlink);
            unset($this->sentAt[$deviceKey . '|' . $downlink->dedupeKey]);
            $this->mark($deviceKey, $downlink, [
                'status' => 'failed',
                'error' => $reason,
                'failedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);

            Logger::channel('hub')->info(
                "Veepoo downlink {$operation} de {$deviceKey} encerrado por {$reason}"
            );
        }
    }

    /**
     * O pedido de onde a ordem veio, pelo identificador; sem ele, o último pedido da operação.
     *
     * @param array<string, mixed> $fields
     */
    private function mark(string $deviceKey, PendingDownlink $downlink, array $fields): void
    {
        $id = (string)($downlink->command['operationId'] ?? $downlink->command['id'] ?? '');
        if ($id !== '') {
            $this->deviceStore?->markCommand($deviceKey, $id, $fields);
        } elseif (GattDownlinks::operationOf($downlink) !== '') {
            $this->deviceStore?->markLatestCommand($deviceKey, GattDownlinks::operationOf($downlink), $fields);
        }
    }

    /** Só pergunta; quem marca é o `markSent`. */
    private function dueForSending(string $deviceKey, string $dedupeKey): bool
    {
        return !isset($this->sentAt[$deviceKey . '|' . $dedupeKey]);
    }

    private function markSent(string $deviceKey, string $dedupeKey): void
    {
        $this->sentAt[$deviceKey . '|' . $dedupeKey] = time();
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
}
