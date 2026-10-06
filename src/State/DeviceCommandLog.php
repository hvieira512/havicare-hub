<?php

declare(strict_types=1);

namespace Hub\State;

/** O ciclo de vida de um comando, do envio à confirmação ou à desistência. */
interface DeviceCommandLog
{
    /** @param array<string, mixed> $record */
    public function recordCommand(string $imei, string $id, array $record): void;

    /** @param array<string, mixed> $fields */
    public function markLatestCommand(string $imei, string $nativeType, array $fields): void;

    /** @param array<string, mixed> $fields */
    public function markCommand(string $imei, string $id, array $fields): void;

    public function isCurrentOperation(string $operationId): bool;

    public function markCommandReply(
        string $imei,
        string $replyNativeType,
        string|int|null $ident = null,
        string $ref = '',
        ?bool $accepted = null,
    ): void;

    public function expireWaitingCommands(int $timeoutSeconds): void;

    /**
     * Reenvia os comandos de configuração em fila e repete os enviados que ainda não foram
     * confirmados.
     *
     * @param callable(string, string, array<string, mixed>): string $dispatch
     */
    public function retryWaitingCommands(
        int $retryAfterSeconds,
        int $timeoutSeconds,
        int $maxAttempts,
        callable $dispatch
    ): void;

    /** @return list<array<string, mixed>> */
    public function commands(string $imei): array;

    /** @return array<string, mixed>|null */
    public function findCommand(string $id): ?array;

    /**
     * Os comandos construídos por ordem de envio, e não um mapa por chave: o hub encaminha
     * isto directamente para o estado do dispositivo Wonlex, logo a forma está no fio.
     *
     * @return list<array<string, mixed>>
     */
    public function desiredConfigurations(string $imei): array;
}
