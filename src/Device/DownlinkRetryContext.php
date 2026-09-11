<?php

declare(strict_types=1);

namespace Hub\Device;

/**
 * O contexto com que uma repetição volta à fila.
 *
 * Nos protocolos que entregam a um gateway, os bytes em fila são só o nome da operação e o
 * valor viaja ao lado. Repetir com os bytes e mais nada põe em fila um comando sem valor --
 * e o gateway executa-o com os campos por preencher.
 */
final class DownlinkRetryContext
{
    /** Os campos do registo do comando que voltam a ser precisos na fila. */
    private const CARRIED = [
        'operationId' => 'operationId',
        'changeId' => 'changeId',
        'genericConfigKey' => 'genericConfigKey',
        'payload' => 'payload',
    ];

    /**
     * @param array<string, mixed> $command o registo guardado do comando
     * @return array<string, mixed>|null
     */
    public static function forCommand(array $command): ?array
    {
        $context = [];
        $name = (string)($command['nativeType'] ?? '');
        if ($name !== '') {
            $context['command'] = $name;
        }

        foreach (self::CARRIED as $from => $to) {
            $value = $command[$from] ?? null;
            if ($value !== null && $value !== '' && $value !== []) {
                $context[$to] = $value;
            }
        }

        return $context === [] ? null : $context;
    }
}
