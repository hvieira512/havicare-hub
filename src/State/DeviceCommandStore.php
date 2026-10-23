<?php

declare(strict_types=1);

namespace Hub\State;

use Hub\Command\Configuration\Payload\FourPTouchPhonebookFallback;
use Hub\Command\DeviceCommandCatalog;
use Hub\Protocol\Adapter\FourPTouchAdapter;
use Predis\ClientInterface;

final class DeviceCommandStore
{
    /** Os estados de que um comando não sai. */
    private const FINISHED = ['acked', 'failed', 'dropped', 'superseded'];

    /** @var null|\Closure(string, string, array<string, mixed>): void */
    private ?\Closure $announce = null;

    public function __construct(
        private ClientInterface $redis,
        private DeviceRuntimeStore $runtime,
        private int $limit = 100,
        private ?DeviceConfigurationProjection $projection = null,
        /** @var (\Closure(string): bool)|null diz se o protocolo ecoa o ident na resposta */
        private ?\Closure $echoesReplyIdent = null,
    ) {
        $this->limit = max(1, $this->limit);
    }

    /** @param callable(string, string, array<string, mixed>): void $announce recebe o aparelho, o `type` do evento e o registo */
    public function announceTo(callable $announce): void
    {
        $this->announce = $announce(...);
    }

    /**
     * O evento que a mudança de estado de um comando publica. O envio por socket anuncia-o o
     * servidor TCP; quem entrega por gateway ou pela cloud pede-o com `announceSent`.
     */
    private static function outcome(string $before, string $after, bool $announceSent): ?string
    {
        if (in_array($before, self::FINISHED, true)) {
            return null;
        }
        if ($announceSent && in_array($after, ['waiting', 'sent'], true)) {
            return 'device.downlink.sent';
        }
        if ($before === $after) {
            return null;
        }

        return match ($after) {
            'acked' => 'device.downlink.acked',
            'failed' => 'device.downlink.failed',
            'superseded' => 'device.downlink.superseded',
            default => null,
        };
    }

    /** @param array<string, mixed> $record */
    public function recordCommand(string $imei, string $id, array $record): void
    {
        $lifecycleAlreadyPersisted = ($record['lifecycleStatusPersisted'] ?? false) === true;
        $announceSent = ($record['announceSent'] ?? false) === true;
        unset($record['lifecycleStatusPersisted'], $record['announceSent']);
        $before = json_decode((string)$this->redis->hget(RedisKeys::commandRecords($imei), $id), true);
        $record['id'] = $id;
        $record['updatedAt'] = gmdate('Y-m-d\\TH:i:s\\Z');
        $record = DeviceCommandRecord::makeJsonSafe($record);
        $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return;
        }
        $this->redis->hset(RedisKeys::commandRecords($imei), $id, $encoded);
        $this->redis->hset(RedisKeys::commandIndex(), $id, $imei);
        $this->redis->lrem(RedisKeys::list($imei, 'commands'), 0, $id);
        $this->redis->lpush(RedisKeys::list($imei, 'commands'), [$id]);
        $evictedIds = array_map(
            'strval',
            $this->redis->lrange(RedisKeys::list($imei, 'commands'), $this->limit, $this->limit)
        );
        $this->redis->pipeline(function ($pipe) use ($imei, $evictedIds): void {
            foreach ($evictedIds as $evictedId) {
                $pipe->hdel(RedisKeys::commandRecords($imei), $evictedId);
                $pipe->hdel(RedisKeys::commandIndex(), $evictedId);
            }
            $pipe->ltrim(RedisKeys::list($imei, 'commands'), 0, $this->limit - 1);
        });
        if (!$lifecycleAlreadyPersisted) {
            $this->projectConfigurationStatus($imei, $id, $record);
        }

        $type = self::outcome((string)(is_array($before) ? $before['status'] ?? '' : ''), (string)($record['status'] ?? ''), $announceSent);
        if ($type !== null && $this->announce !== null) {
            ($this->announce)($imei, $type, $record);
        }
    }

    /** @param callable(string, string, array<string, mixed>): string $dispatch */
    public function retryWaitingCommands(int $retryAfterSeconds, int $timeoutSeconds, int $maxAttempts, callable $dispatch): void
    {
        $retryAfterSeconds = max(1, $retryAfterSeconds);
        $timeoutSeconds = max($retryAfterSeconds, $timeoutSeconds);
        $maxAttempts = max(1, $maxAttempts);
        $now = time();

        foreach ($this->runtime->devices() as $device) {
            $imei = (string)($device['imei'] ?? '');
            if ($imei === '') {
                continue;
            }

            foreach ($this->commands($imei) as $command) {
                $commandStatus = (string)($command['status'] ?? '');
                if (!in_array($commandStatus, ['queued', 'waiting'], true)) {
                    continue;
                }
                if (!($command['retryable'] ?? false)) {
                    continue;
                }
                $operationId = (string)($command['operationId'] ?? '');
                if (
                    $operationId !== ''
                    && $this->projection !== null
                    && !$this->projection->isCurrentOperation($operationId)
                ) {
                    $this->finish($imei, $command, 'superseded');
                    continue;
                }

                $bytes = DeviceCommandRecord::wireBytes($command);
                if ($bytes === '') {
                    continue;
                }

                $attempts = max(1, (int)($command['attempts'] ?? 1));
                $commandRetryAfterSeconds = max(1, (int)($command['retryDelaySeconds'] ?? $retryAfterSeconds));
                $sentAt = strtotime((string)($command['sentAt'] ?? '')) ?: 0;
                $nextRetryAt = strtotime((string)($command['nextRetryAt'] ?? '')) ?: 0;

                if ($commandStatus === 'waiting' && $sentAt > 0 && ($now - $sentAt) >= $timeoutSeconds) {
                    $this->finish($imei, $command, 'failed', 'response_timeout');
                    continue;
                }

                if ($nextRetryAt > 0 && $nextRetryAt > $now) {
                    continue;
                }

                if ($commandStatus === 'waiting' && $attempts >= $maxAttempts) {
                    $this->finish($imei, $command, 'failed', 'retry_exhausted');
                    continue;
                }

                $status = (string)$dispatch($imei, $bytes, $command);
                $updatedAttempts = $attempts;
                // Um comando que já tinha saído conta como tentativa, também quando volta à fila de um
                // gateway ou da cloud, que o entregam de novo sem resposta do aparelho.
                if (in_array($status, ['sent', 'queued'], true) && $commandStatus === 'waiting') {
                    $updatedAttempts++;
                }
                $updated = array_merge($command, [
                    'attempts' => $updatedAttempts,
                    'lastAttemptAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
                    'nextRetryAt' => gmdate('Y-m-d\\TH:i:s\\Z', $now + $commandRetryAfterSeconds),
                ]);

                if ($status === 'sent') {
                    $updated['status'] = 'waiting';
                    $updated['sentAt'] = gmdate('Y-m-d\\TH:i:s\\Z');
                    unset($updated['error'], $updated['lastError']);
                    $this->recordCommand($imei, (string)$command['id'], $updated);
                    continue;
                }

                if ($status === 'queued') {
                    $this->finish($imei, $updated, 'queued');
                    continue;
                }

                // O `device.downlink.dropped` já saiu de quem não conseguiu pôr na fila.
                $this->finish($imei, $updated, 'dropped', 'delivery_failed');
            }

            $this->fallBackToLegacyPhonebook($imei, $dispatch);
        }
    }

    /**
     * Um 4P Touch que não confirmou uma única escrita indexada recebe a lista telefónica na forma
     * antiga; as escritas por confirmar passam a `superseded`, para o recuo não se repetir.
     */
    private function fallBackToLegacyPhonebook(string $imei, callable $dispatch): void
    {
        $phonebook = array_values(array_filter(
            $this->commands($imei),
            static fn(array $command): bool => (string)($command['protocol'] ?? '') === 'four-p-touch'
                && (string)($command['nativeKey'] ?? '') === 'phonebook'
        ));
        if (!FourPTouchPhonebookFallback::shouldFallBack($phonebook)) {
            return;
        }

        $adapter = new FourPTouchAdapter();
        $deviceId = '';
        foreach ($phonebook as $command) {
            $deviceId = (string)($adapter->decodeIncoming(DeviceCommandRecord::wireBytes($command))['ident'] ?? '');
            if ($deviceId !== '') {
                break;
            }
        }
        if ($deviceId === '') {
            return;
        }

        // As escritas indexadas saem primeiro: a lista antiga toma o lugar delas na mesma alteração.
        foreach ($phonebook as $command) {
            $this->finish($imei, $command, 'superseded');
        }

        $template = $phonebook[0];
        $changeId = (string)($template['changeId'] ?? '');
        $contacts = FourPTouchPhonebookFallback::contactsFromCommands($phonebook);
        foreach (FourPTouchPhonebookFallback::commands($contacts) as $legacy) {
            $payload = ['fields' => $legacy['fields']];
            $bytes = DeviceCommandCatalog::buildDownlink(
                'four-p-touch',
                $imei,
                $legacy['command'],
                $payload,
                ['deviceId' => $deviceId]
            );
            $id = bin2hex(random_bytes(16));
            // Operação da alteração que substitui, para a confirmação do relógio a fechar.
            $operationId = $changeId !== '' && $this->projection?->addOperation($changeId, $id, 'phonebook', $legacy['command'], 'four-p-touch') === true
                ? $id
                : '';
            $record = array_merge($template, [
                'id' => $id,
                'operationId' => $operationId,
                'nativeType' => $legacy['command'],
                'payload' => $payload,
                'bytes' => $bytes,
                'attempts' => 1,
                'status' => match ((string)$dispatch($imei, $bytes, array_merge($template, ['id' => $id, 'operationId' => $operationId]))) {
                    'sent' => 'waiting',
                    'dropped' => 'dropped',
                    default => 'queued',
                },
                'sentAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
                'error' => '',
                'lastError' => '',
            ]);
            unset($record['bytesEncoding'], $record['ackedAt'], $record['failedAt']);
            $this->recordCommand($imei, $id, $record);
        }
    }

    /** @param array<string, mixed> $fields */
    public function markLatestCommand(string $imei, string $nativeType, array $fields): void
    {
        foreach ($this->commands($imei) as $command) {
            if (($command['nativeType'] ?? '') !== $nativeType) {
                continue;
            }
            if (in_array((string)($command['status'] ?? ''), self::FINISHED, true)) {
                continue;
            }
            $this->recordCommand($imei, (string)$command['id'], array_merge($command, $fields));
            return;
        }
    }

    /** @param array<string, mixed> $fields */
    public function markCommand(string $imei, string $id, array $fields): void
    {
        foreach ($this->commands($imei) as $command) {
            if ((string)($command['id'] ?? '') === $id) {
                if (!in_array((string)($command['status'] ?? ''), self::FINISHED, true)) {
                    $this->recordCommand($imei, $id, array_merge($command, $fields));
                }
                return;
            }
        }
    }

    public function isCurrentOperation(string $operationId): bool
    {
        return $this->projection === null || $this->projection->isCurrentOperation($operationId);
    }

    public function markCommandReply(
        string $imei,
        string $replyNativeType,
        string|int|null $ident = null,
        string $ref = '',
        ?bool $accepted = null,
        string $error = '',
    ): void {
        $uncorrelatedMatch = null;
        $semanticMatch = null;

        foreach ($this->commands($imei) as $command) {
            if (!in_array((string)($command['status'] ?? ''), ['waiting'], true)) {
                continue;
            }
            $expected = $command['expectedReplyTypes'] ?? [];
            if (!is_array($expected) || !in_array($replyNativeType, $expected, true)) {
                continue;
            }
            $commandIdent = $command['ident'] ?? null;

            if ($commandIdent !== null && $ident !== null && (string)$commandIdent === (string)$ident) {
                $this->completeCommandReply($imei, $command, $replyNativeType, $ident, $ref, $accepted, $error);
                return;
            }

            $echoes = $this->echoesReplyIdent === null || ($this->echoesReplyIdent)((string)($command['protocol'] ?? ''));
            if ($commandIdent === null || ($ident === null && $echoes)) {
                $uncorrelatedMatch ??= $command;
                continue;
            }

            // Sem eco do ident, vale o pendente mais recente que espera esta resposta.
            if (!$echoes) {
                $semanticMatch ??= $command;
            }
        }

        $command = $uncorrelatedMatch ?? $semanticMatch;
        if (is_array($command)) {
            $this->completeCommandReply($imei, $command, $replyNativeType, $ident, $ref, $accepted, $error);
        }
    }

    public function expireWaitingCommands(int $timeoutSeconds): void
    {
        $cutoff = time() - max(1, $timeoutSeconds);
        foreach ($this->runtime->devices() as $device) {
            $imei = (string)($device['imei'] ?? '');
            if ($imei === '') {
                continue;
            }
            foreach ($this->commands($imei) as $command) {
                $status = (string)($command['status'] ?? '');
                if (!in_array($status, ['waiting', 'queued'], true)) {
                    continue;
                }
                // Em fila e repetível quer dizer "à espera de o dispositivo voltar": esse é
                // da varredura de repetição, que o reenvia o tempo que for preciso.
                if ($status === 'queued' && ($command['retryable'] ?? false)) {
                    continue;
                }
                $startedAt = $this->commandStartedAt($command);
                if ($startedAt > 0 && $startedAt <= $cutoff) {
                    // Nunca enviado quer dizer que o dispositivo não nos deve nada: só um
                    // comando que saiu de facto é que esgotou a espera.
                    $error = $status === 'waiting' ? 'response_timeout' : 'delivery_failed';
                    $this->finish($imei, $command, 'failed', $error);
                }
            }
        }
    }

    /**
     * Quando é que um comando começou a contar para o tempo limite. Sem `sentAt`, recorre-se a
     * outro campo, para o que nunca chegou ao dispositivo não ficar pendente para sempre.
     *
     * @param array<string, mixed> $command
     */
    private function commandStartedAt(array $command): int
    {
        foreach (['sentAt', 'lastAttemptAt', 'requestedAt'] as $field) {
            $timestamp = strtotime((string)($command[$field] ?? '')) ?: 0;
            if ($timestamp > 0) {
                return $timestamp;
            }
        }

        return 0;
    }

    /** @return list<array<string, mixed>> */
    public function commands(string $imei): array
    {
        $ids = $this->redis->lrange(RedisKeys::list($imei, 'commands'), 0, $this->limit - 1);
        if ($ids === []) {
            return [];
        }

        // Uma ida e volta para a página toda em vez de uma por id: o stream relê isto a cada push.
        $raws = $this->redis->hmget(RedisKeys::commandRecords($imei), array_map(strval(...), $ids));

        $commands = [];
        foreach ($raws as $raw) {
            if (!is_string($raw)) {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $commands[] = $decoded;
            }
        }

        return $commands;
    }

    /** @param array<string, mixed> $record */
    private function projectConfigurationStatus(string $imei, string $id, array $record): void
    {
        if ($this->projection === null || !isset($record['configKey'])) {
            return;
        }

        $status = (string)($record['status'] ?? '');
        if ($status === '') {
            return;
        }
        $this->projection->markApplyStatus(
            $imei,
            (string)$record['configKey'],
            $status,
            $id,
            (string)(($record['lastError'] ?? '') ?: ($record['error'] ?? ''))
        );
    }

    /** @param array<string, mixed> $command */
    private function completeCommandReply(
        string $imei,
        array $command,
        string $replyNativeType,
        string|int|null $ident,
        string $ref,
        ?bool $accepted,
        string $error = '',
    ): void {
        $id = (string)$command['id'];
        $replyFields = [
            'replyNativeType' => $replyNativeType,
            'replyIdent' => $ident,
            'replyRef' => $ref,
        ];
        if ($accepted === false) {
            $this->finish($imei, array_merge($command, $replyFields, [
                'failedAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
            ]), 'failed', $error !== '' ? $error : 'device_rejected');
            return;
        }

        $this->recordCommand($imei, $id, array_merge($command, $replyFields, [
            'status' => 'acked',
            'ackedAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
        ]));
    }

    /**
     * O comando passa ao estado dado, com o erro no `error` e no `lastError`.
     *
     * @param array<string, mixed> $command
     */
    private function finish(string $imei, array $command, string $status, string $error = ''): void
    {
        $this->recordCommand($imei, (string)$command['id'], array_merge($command, [
            'status' => $status,
            'error' => $error,
            'lastError' => $error,
        ]));
    }

    /** @return array<string, mixed>|null */
    public function findCommand(string $id): ?array
    {
        $imei = (string)($this->redis->hget(RedisKeys::commandIndex(), $id) ?? '');
        if ($imei === '') {
            return null;
        }

        $raw = $this->redis->hget(RedisKeys::commandRecords($imei), $id);
        if (!is_string($raw)) {
            $this->redis->hdel(RedisKeys::commandIndex(), [$id]);
            return null;
        }

        $command = json_decode($raw, true);
        if (is_array($command)) {
            return [
                'device' => $this->runtime->device($imei),
                'command' => $command,
            ];
        }

        return null;
    }
}
