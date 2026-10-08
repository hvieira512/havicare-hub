<?php

declare(strict_types=1);

namespace Hub\Domain\Capability\Definition;

/** `help_call` porque é o que o `MessageNormalizer` publica quando o `key` da Voerka é `8`. */
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
                    'reset' => 'Chamada reposta',
                ],
            ],
        ];
    }
}
