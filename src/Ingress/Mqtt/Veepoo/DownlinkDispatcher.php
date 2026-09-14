<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Veepoo;

use Hub\Dashboard\DashboardStoreContract;
use Hub\Device\HubMqttBridge;
use Hub\Device\PendingDownlinkQueue;
use Hub\Log\Logger;

/**
 * Entrega às pulseiras o que a API ou o ecrã puseram em fila, e fecha o ciclo quando o
 * gateway confirma.
 *
 * Publica no canal de comandos do próprio gateway, e não no do aparelho: quem executa é a
 * caixa, que tem a sessão BLE. A criação continua a ser exclusiva da API REST -- isto é
 * entrega, o equivalente ao socket por onde um relógio recebe os seus.
 */
final class DownlinkDispatcher
{
    /**
     * Quanto tempo um comando entregue e não confirmado espera antes de ser repetido.
     *
     * O gateway ignora a mesma chave durante minutos para não executar duas vezes a mesma
     * entrega, por isso repeti-la a cada volta do temporizador não o acordaria -- só encheria
     * o tópico. A repetição serve para o caso de a entrega se ter perdido.
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
        private readonly ?DashboardStoreContract $dashboardStore,
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
            // Para este protocolo os bytes em fila são o próprio nome da operação: quem os
            // monta é o `DeviceCommandCatalog`, que aqui não tem trama que construir.
            $command = $downlink->command ?? [];
            $operation = (string)($command['command'] ?? $downlink->bytes);
            if ($operation === '' || !$this->dueForSending($downlink->dedupeKey)) {
                continue;
            }

            $this->mqttBridge->publishGatewayCommand($this->commandTopicFor($gatewayKey), [
                'deviceId' => $deviceKey,
                'operation' => $operation,
                // O nome da operação diz o que fazer e não com que valor. Sem isto, desligar
                // um interruptor chegava ao gateway indistinguível de o ligar, e uma ordem de
                // parar -- como a de deixar de procurar a pulseira -- não existia de todo.
                'payload' => $command['payload'] ?? null,
                'dedupeKey' => $downlink->dedupeKey,
                'commandId' => $command['id'] ?? null,
                'expiresAt' => $downlink->expiresAt,
            ]);

            // Só depois de sair. Marcar ao perguntar se era devida deixava uma ordem que
            // estoirou a publicar calada trinta segundos sem nunca ter saído.
            $this->markSent($downlink->dedupeKey);

            // Fica em fila de propósito. Entregar não é executar: se o gateway não chegar a
            // correr o comando, a sessão seguinte volta a recebê-lo, e o TTL da política é
            // que decide quando deixa de fazer sentido. Sai da fila em `resolvePending`,
            // quando a caixa confirma -- caso contrário perdia-se em silêncio.
            $this->dashboardStore?->markLatestCommand($deviceKey, $operation, [
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
            // Confirmado é caso encerrado: a chave sai do travão de repetição para que a
            // ordem seguinte -- mandar vibrar outra vez, por exemplo -- saia na hora.
            unset($this->sentAt[$dedupeKey]);
            $operation = (string)(($downlink->command['command'] ?? null) ?? $downlink->bytes);
            if ($operation !== '') {
                $this->dashboardStore?->markLatestCommand($deviceKey, $operation, [
                    'status' => 'acked',
                    'ackedAt' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
            }

            Logger::channel('hub')->info("Veepoo downlink {$dedupeKey} confirmado por {$deviceKey}");
            return;
        }
    }

    /** Se esta chave já saiu há pouco. Pergunta e mais nada -- quem marca é o `markSent`. */
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

    /**
     * O canal de comandos vive ao lado do de entrada, no mesmo espaço fixo do gateway:
     * `.../gw/{mac}/raw` para o que sobe, `.../gw/{mac}/cmd` para o que desce.
     */
    private function commandTopicFor(string $gatewayKey): string
    {
        $base = preg_replace('#/\+/raw$#', '', trim($this->topicFilter, '/')) ?? '';

        return $base . '/' . $gatewayKey . '/cmd';
    }
}
