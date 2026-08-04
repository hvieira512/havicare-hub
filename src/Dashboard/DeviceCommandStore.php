<?php

namespace Hub\Dashboard;

use Hub\Command\DeviceCommandCatalog;
use Predis\ClientInterface;

final class DeviceCommandStore
{
    public function __construct(
        private ClientInterface $redis,
        private DeviceRuntimeStore $runtime,
        private int $limit = 100,
        private string $prefix = 'hub:dashboard',
        private ?DeviceConfigurationProjection $projection = null,
    ) {
        $this->prefix = trim($this->prefix, ':');
        $this->limit = max(1, $this->limit);
    }

    public function recordCommand(string $imei, string $id, array $record): void
    {
        $lifecycleAlreadyPersisted = ($record['lifecycleStatusPersisted'] ?? false) === true;
        unset($record['lifecycleStatusPersisted']);
        $record['id'] = $id;
        $record['updatedAt'] = gmdate('Y-m-d\\TH:i:s\\Z');
        $record = DeviceCommandRecord::makeJsonSafe($record);
        $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return;
        }
        $this->redis->hset($this->commandHashKey($imei), $id, $encoded);
        $this->redis->hset($this->commandIndexKey(), $id, $imei);
        $this->redis->lrem($this->deviceListKey($imei, 'commands'), 0, $id);
        $this->redis->lpush($this->deviceListKey($imei, 'commands'), [$id]);
        $this->redis->ltrim($this->deviceListKey($imei, 'commands'), 0, $this->limit - 1);
        if (!$lifecycleAlreadyPersisted) {
            $this->projectConfigurationStatus($imei, $id, $record);
        }
    }

    /**
     * @param callable(string, string, array): string $dispatch
     */
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
                    $this->recordCommand($imei, (string)$command['id'], array_merge($command, [
                        'status' => 'superseded',
                        'error' => '',
                        'lastError' => '',
                    ]));
                    continue;
                }

                $bytes = DeviceCommandRecord::wireBytes($command);
                if ($bytes === '') {
                    continue;
                }
                $normalizedBytes = DeviceCommandCatalog::normalizeQueuedDownlink(
                    (string)($command['protocol'] ?? ''),
                    $bytes
                );
                if ($normalizedBytes !== $bytes) {
                    $bytes = $normalizedBytes;
                    $command['bytes'] = $bytes;
                    unset($command['bytesEncoding']);
                }

                $attempts = max(1, (int)($command['attempts'] ?? 1));
                $commandMaxAttempts = max(1, (int)($command['maxAttempts'] ?? $maxAttempts));
                $commandRetryAfterSeconds = max(1, (int)($command['retryDelaySeconds'] ?? $retryAfterSeconds));
                $sentAt = strtotime((string)($command['sentAt'] ?? '')) ?: 0;
                $nextRetryAt = strtotime((string)($command['nextRetryAt'] ?? '')) ?: 0;

                if ($commandStatus === 'waiting' && $sentAt > 0 && ($now - $sentAt) >= $timeoutSeconds) {
                    $this->recordCommand($imei, (string)$command['id'], array_merge($command, [
                        'status' => 'failed',
                        'error' => 'response_timeout',
                        'lastError' => 'response_timeout',
                    ]));
                    continue;
                }

                if ($nextRetryAt > 0 && $nextRetryAt > $now) {
                    continue;
                }

                if ($commandStatus === 'waiting' && $attempts >= $commandMaxAttempts) {
                    $this->recordCommand($imei, (string)$command['id'], array_merge($command, [
                        'status' => 'failed',
                        'error' => 'retry_exhausted',
                        'lastError' => 'retry_exhausted',
                    ]));
                    continue;
                }

                $status = (string)$dispatch($imei, $bytes, $command);
                $updatedAttempts = $attempts;
                if ($status === 'sent' && $commandStatus === 'waiting') {
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
                    $updated['status'] = 'queued';
                    $updated['error'] = '';
                    $updated['lastError'] = '';
                    $this->recordCommand($imei, (string)$command['id'], $updated);
                    continue;
                }

                $updated['status'] = 'failed';
                $updated['error'] = 'delivery_failed';
                $updated['lastError'] = 'delivery_failed';
                $this->recordCommand($imei, (string)$command['id'], $updated);
            }
        }
    }

    public function markLatestCommand(string $imei, string $nativeType, array $fields): void
    {
        foreach ($this->commands($imei) as $command) {
            if (($command['nativeType'] ?? '') !== $nativeType) {
                continue;
            }
            if (in_array((string)($command['status'] ?? ''), ['acked', 'failed', 'dropped', 'superseded'], true)) {
                continue;
            }
            $this->recordCommand($imei, (string)$command['id'], array_merge($command, $fields));
            return;
        }
    }

    public function markCommand(string $imei, string $id, array $fields): void
    {
        foreach ($this->commands($imei) as $command) {
            if ((string)($command['id'] ?? '') === $id) {
                $this->recordCommand($imei, $id, array_merge($command, $fields));
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
    ): void
    {
        $uncorrelatedMatch = null;
        $wonlexSemanticMatch = null;

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
                $this->completeCommandReply($imei, $command, $replyNativeType, $ident, $ref, $accepted);
                return;
            }

            if ($commandIdent === null || ($ident === null && ($command['protocol'] ?? '') !== 'wonlex-json')) {
                $uncorrelatedMatch ??= $command;
                continue;
            }

            // Some Wonlex firmwares generate a fresh ident for both w:reply and
            // w:update instead of echoing the downlink ident documented by the
            // protocol. Preserve exact-ident priority, then fall back to the
            // newest pending command that semantically expects this reply.
            if (($command['protocol'] ?? '') === 'wonlex-json') {
                $wonlexSemanticMatch ??= $command;
                continue;
            }
        }

        $command = $uncorrelatedMatch ?? $wonlexSemanticMatch;
        if (is_array($command)) {
            $this->completeCommandReply($imei, $command, $replyNativeType, $ident, $ref, $accepted);
        }
    }

    public function expireWaitingCommands(int $timeoutSeconds): void
    {
        $cutoff = time() - max(1, $timeoutSeconds);
        foreach ($this->runtime->devices() as $device) {
            $imei = (string)($device['imei'] ?? '');
            foreach ($this->commands($imei) as $command) {
                if (($command['status'] ?? '') !== 'waiting') {
                    continue;
                }
                $sentAt = strtotime((string)($command['sentAt'] ?? '')) ?: 0;
                if ($sentAt > 0 && $sentAt <= $cutoff) {
                    $this->recordCommand($imei, (string)$command['id'], array_merge($command, ['status' => 'failed', 'error' => 'response_timeout']));
                }
            }
        }
    }

    public function commands(string $imei): array
    {
        $commands = [];
        foreach ($this->redis->lrange($this->deviceListKey($imei, 'commands'), 0, $this->limit - 1) as $id) {
            $raw = $this->redis->hget($this->commandHashKey($imei), (string)$id);
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $commands[] = $decoded;
                }
            }
        }
        return $commands;
    }

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
            (string)($record['lastError'] ?? $record['error'] ?? '')
        );
    }

    private function completeCommandReply(
        string $imei,
        array $command,
        string $replyNativeType,
        string|int|null $ident,
        string $ref,
        ?bool $accepted,
    ): void {
        $id = (string)$command['id'];
        $replyFields = [
            'replyNativeType' => $replyNativeType,
            'replyIdent' => $ident,
            'replyRef' => $ref,
        ];
        if ($accepted === false) {
            $this->recordCommand($imei, $id, array_merge($command, $replyFields, [
                'status' => 'failed',
                'failedAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
                'error' => 'device_rejected',
                'lastError' => 'device_rejected',
            ]));
            return;
        }

        $this->recordCommand($imei, $id, array_merge($command, $replyFields, [
            'status' => 'acked',
            'ackedAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
        ]));
    }

    public function findCommand(string $id): ?array
    {
        $imei = (string)($this->redis->hget($this->commandIndexKey(), $id) ?? '');
        if ($imei === '') {
            return null;
        }

        $raw = $this->redis->hget($this->commandHashKey($imei), $id);
        if (!is_string($raw)) {
            $this->redis->hdel($this->commandIndexKey(), $id);
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

    private function key(string $suffix): string
    {
        return "{$this->prefix}:{$suffix}";
    }

    private function deviceListKey(string $imei, string $list): string
    {
        return $this->key("device:{$imei}:{$list}");
    }

    private function commandHashKey(string $imei): string
    {
        return $this->key("device:{$imei}:command-records");
    }

    private function commandIndexKey(): string
    {
        return $this->key('command-index');
    }
}
