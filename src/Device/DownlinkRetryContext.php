<?php

declare(strict_types=1);

namespace Hub\Device;

/**
 * O contexto com que uma repetição volta à fila: nos protocolos de gateway os bytes em fila
 * são só o nome da operação, e o valor viaja ao lado.
 */
final class DownlinkRetryContext
{
    /** Os campos do registo do comando que voltam a ser precisos na fila. */
    private const CARRIED = [
        // O identificador do pedido, que é por onde o gateway sabe que isto é a mesma ordem
        // e não alguém a carregar outra vez no botão.
        'id' => 'id',
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
