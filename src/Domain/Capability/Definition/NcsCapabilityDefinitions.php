<?php

declare(strict_types=1);

namespace Hub\Domain\Capability\Definition;

/**
 * A chave é `help_call` e não `pager_call` porque é `help_call` que o `MessageNormalizer`
 * publica quando o `key` da Voerka é `8`. Enquanto foram dois nomes, quem integrasse pelo
 * catálogo ficava à espera de um evento que nunca chegava. A pulseira MOKO, que também
 * tem botão, já usava `help_call` nos dois sítios.
 */
final class NcsCapabilityDefinitions extends CapabilityDefinitions
{
    protected static function deviceType(): string
    {
        return 'ncs';
    }

    protected static function rows(): array
    {
        return [
            'alarms' => [
                'event' => [
                    'help_call' => 'Chamada de ajuda',
                ],
            ],
        ];
    }
}
