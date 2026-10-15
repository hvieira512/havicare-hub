<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Lefu;

use DateTimeImmutable;
use Hub\Device\HubMqttBridge;
use Hub\Device\PendingDownlink;
use Hub\Device\PendingDownlinkQueue;
use Hub\Log\Logger;
use Hub\State\DeviceCommandLog;

/**
 * Entrega à balança, pelo gateway com a sessão GATT, o que está em fila. A entrega não fecha o
 * comando: fecha-o a resposta da balança, ou o gateway quando a ordem não tem resposta.
 */
final class LefuDownlinks
{
    /** Uma entrega sem `command_result` volta a sair ao fim disto. */
    private const RESEND_SECONDS = 30;

    /** @var array<string, int> */
    private array $sentAt = [];

    public function __construct(
        private readonly ?PendingDownlinkQueue $downlinks,
        private readonly HubMqttBridge $mqttBridge,
        private readonly ?DeviceCommandLog $deviceStore,
        private readonly string $topicFilter,
        private readonly TorreCommandEncoder $encoder = new TorreCommandEncoder(),
    ) {
    }

    /**
     * @param callable(string, array<string, mixed>): void $onSent quem foi entregue, com o payload
     */
    public function dispatchPending(string $deviceKey, string $gatewayKey, int $mtu, int $offsetMinutes, callable $onSent): void
    {
        if ($this->downlinks === null) {
            return;
        }

        $now = time();
        foreach ($this->downlinks->pendingFor($deviceKey) as $downlink) {
            if ($now - ($this->sentAt[$downlink->dedupeKey] ?? 0) < self::RESEND_SECONDS) {
                continue;
            }
            $operation = self::operationOf($downlink);
            $payload = is_array($downlink->command['payload'] ?? null) ? $downlink->command['payload'] : [];

            try {
                $writes = $this->encoder->writes($operation, $payload, $mtu, new DateTimeImmutable(), $offsetMinutes);
            } catch (\InvalidArgumentException $error) {
                $this->downlinks->remove($downlink);
                $this->mark($deviceKey, $downlink, ['status' => 'failed', 'error' => $error->getMessage()]);
                continue;
            }

            $this->publish($gatewayKey, $deviceKey, $operation, $downlink->dedupeKey, self::commandId($downlink), $writes, $downlink->expiresAt);
            $this->sentAt[$downlink->dedupeKey] = $now;
            $this->mark($deviceKey, $downlink, ['status' => 'waiting', 'sentAt' => gmdate('Y-m-d\TH:i:s\Z')]);
            $onSent($operation, $payload);
        }
    }

    /**
     * Uma ordem que não passa pela fila nem pelo registo de comandos, como o acerto do relógio a
     * cada ligação.
     *
     * @param array<string, mixed> $payload
     */
    public function sendDirect(string $deviceKey, string $gatewayKey, string $operation, array $payload, int $mtu, int $offsetMinutes): void
    {
        $writes = $this->encoder->writes($operation, $payload, $mtu, new DateTimeImmutable(), $offsetMinutes);
        $this->publish($gatewayKey, $deviceKey, $operation, $operation . ':' . $deviceKey . ':' . time(), null, $writes, time() + 60);
    }

    /** O gateway escreveu. Sai da fila; o estado só muda aqui se a ordem não tiver resposta. */
    public function delivered(string $deviceKey, string $dedupeKey, bool $ok, string $error): void
    {
        if ($this->downlinks === null || $dedupeKey === '') {
            return;
        }

        foreach ($this->downlinks->pendingFor($deviceKey) as $downlink) {
            if ($downlink->dedupeKey !== $dedupeKey) {
                continue;
            }
            $this->downlinks->remove($downlink);
            unset($this->sentAt[$dedupeKey]);
            if (!$ok) {
                $this->mark($deviceKey, $downlink, ['status' => 'failed', 'error' => $error !== '' ? $error : 'write_failed']);
            } elseif ($this->expectsNoReply($downlink)) {
                $this->mark($deviceKey, $downlink, ['status' => 'acked', 'ackedAt' => gmdate('Y-m-d\TH:i:s\Z')]);
            }

            return;
        }
    }

    /** @param list<array<string, mixed>> $writes */
    private function publish(string $gatewayKey, string $deviceKey, string $operation, string $dedupeKey, ?string $commandId, array $writes, int $expiresAt): void
    {
        $this->mqttBridge->publishGatewayCommand($this->commandTopicFor($gatewayKey), [
            'deviceId' => $deviceKey,
            'commandId' => $commandId,
            'dedupeKey' => $dedupeKey,
            'operation' => $operation,
            'payload' => ['writes' => $writes],
            'expiresAt' => $expiresAt,
        ]);
        Logger::channel('hub')->info("Lefu downlink {$operation} entregue ao gateway {$gatewayKey} para {$deviceKey}");
    }

    /** @param array<string, mixed> $fields */
    private function mark(string $deviceKey, PendingDownlink $downlink, array $fields): void
    {
        $id = self::commandId($downlink);
        if ($id !== null) {
            $this->deviceStore?->markCommand($deviceKey, $id, $fields);
        }
    }

    private function expectsNoReply(PendingDownlink $downlink): bool
    {
        $id = self::commandId($downlink);
        $record = $id === null ? null : $this->deviceStore?->findCommand($id);
        $command = is_array($record['command'] ?? null) ? $record['command'] : ($record ?? []);

        return ($command['expectedReplyTypes'] ?? []) === [];
    }

    private static function operationOf(PendingDownlink $downlink): string
    {
        return (string)(($downlink->command['command'] ?? null) ?? $downlink->bytes);
    }

    private static function commandId(PendingDownlink $downlink): ?string
    {
        $id = (string)($downlink->command['operationId'] ?? $downlink->command['id'] ?? '');

        return $id !== '' ? $id : null;
    }

    /** `.../gw/+/raw` para o que sobe, `.../gw/{id}/cmd` para o que desce. */
    private function commandTopicFor(string $gatewayKey): string
    {
        $base = preg_replace('#/\+/raw$#', '', trim($this->topicFilter, '/')) ?? '';

        return $base . '/' . $gatewayKey . '/cmd';
    }
}
