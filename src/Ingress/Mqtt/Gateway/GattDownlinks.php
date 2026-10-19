<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Gateway;

use DateTimeImmutable;
use Hub\Device\HubMqttBridge;
use Hub\Device\PendingDownlink;
use Hub\Device\PendingDownlinkQueue;
use Hub\Log\Logger;
use Hub\State\DeviceCommandLog;

/**
 * Entrega a um aparelho, pelo gateway com a sessão GATT, o que está em fila. A entrega não fecha o
 * comando: fecha-o a resposta do aparelho, ou o gateway quando a ordem não tem resposta.
 */
final class GattDownlinks
{
    /** Uma entrega sem `command_result` volta a sair ao fim disto: mais do que os 30 s de um `await`. */
    private const RESEND_SECONDS = 40;

    public const NOT_CONNECTED = 'not_connected';

    /** @var array<string, int> por aparelho e chave: a mesma ordem a dois aparelhos tem a mesma chave */
    private array $sentAt = [];

    /**
     * @param \Closure(string, array<string, mixed>, int, DateTimeImmutable, int): list<array<string, mixed>> $encoder
     *     as escritas GATT de cada operação, montadas pelo protocolo do aparelho
     */
    public function __construct(
        private readonly ?PendingDownlinkQueue $downlinks,
        private readonly HubMqttBridge $mqttBridge,
        private readonly ?DeviceCommandLog $deviceStore,
        private readonly string $topicFilter,
        private readonly \Closure $encoder,
    ) {
    }

    /** @return list<string> as operações que saíram */
    public function dispatchPending(string $deviceKey, string $gatewayKey, int $mtu, int $offsetMinutes): array
    {
        if ($this->downlinks === null) {
            return [];
        }

        $sent = [];

        $now = time();
        $pending = $this->downlinks->pendingFor($deviceKey);
        $this->forgetGone($deviceKey, $pending);
        // O gateway faz uma ordem de cada vez: com uma a caminho, a seguinte espera por ela.
        foreach ($pending as $downlink) {
            if ($now - ($this->sentAt[$deviceKey . '|' . $downlink->dedupeKey] ?? 0) < self::RESEND_SECONDS) {
                return $sent;
            }
            $operation = self::operationOf($downlink);
            $payload = is_array($downlink->command['payload'] ?? null) ? $downlink->command['payload'] : [];

            try {
                $writes = $this->writes($operation, $payload, $mtu, $offsetMinutes);
            } catch (\InvalidArgumentException $error) {
                $this->downlinks->remove($downlink);
                $this->mark($deviceKey, $downlink, ['status' => 'failed', 'error' => $error->getMessage()]);
                continue;
            }

            $this->publish($gatewayKey, $deviceKey, $operation, $downlink->dedupeKey, self::commandId($downlink), $writes, $downlink->expiresAt);
            $this->sentAt[$deviceKey . '|' . $downlink->dedupeKey] = $now;
            $this->mark($deviceKey, $downlink, ['status' => 'waiting', 'sentAt' => gmdate('Y-m-d\TH:i:s\Z')]);
            $sent[] = $operation;

            return $sent;
        }

        return $sent;
    }

    /**
     * O travão de quem já saiu da fila, por expirar ou por outro caminho, não trava nada.
     *
     * @param list<PendingDownlink> $pending
     */
    private function forgetGone(string $deviceKey, array $pending): void
    {
        $keep = [];
        foreach ($pending as $downlink) {
            $keep[$deviceKey . '|' . $downlink->dedupeKey] = true;
        }
        foreach (array_keys($this->sentAt) as $key) {
            if (str_starts_with($key, $deviceKey . '|') && !isset($keep[$key])) {
                unset($this->sentAt[$key]);
            }
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
        $writes = $this->writes($operation, $payload, $mtu, $offsetMinutes);
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
            unset($this->sentAt[$deviceKey . '|' . $dedupeKey]);
            // O aparelho já não estava ligado: a ordem espera pela ligação seguinte.
            if (!$ok && $error === self::NOT_CONNECTED) {
                return;
            }
            $this->downlinks->remove($downlink);
            if (!$ok) {
                $this->mark($deviceKey, $downlink, ['status' => 'failed', 'error' => $error !== '' ? $error : 'write_failed']);
            } elseif ($this->expectsNoReply($downlink)) {
                $this->mark($deviceKey, $downlink, ['status' => 'acked', 'ackedAt' => gmdate('Y-m-d\TH:i:s\Z')]);
            }

            return;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function writes(string $operation, array $payload, int $mtu, int $offsetMinutes): array
    {
        return ($this->encoder)($operation, $payload, $mtu, new DateTimeImmutable(), $offsetMinutes);
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
        Logger::channel('hub')->info("Downlink GATT {$operation} entregue ao gateway {$gatewayKey} para {$deviceKey}");
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
