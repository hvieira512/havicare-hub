<?php

namespace Hub\Domain\Capability\Definition;

final class NcsCapabilityDefinitions
{
    /**
     * A chave é `help_call` e não `pager_call` porque é `help_call` que o `MessageNormalizer`
     * publica quando o `key` da Voerka é `8`. Enquanto foram dois nomes, quem integrasse pelo
     * catálogo ficava à espera de um evento que nunca chegava. A pulseira MOKO, que também
     * tem botão, já usava `help_call` nos dois sítios.
     *
     * @return list<array{deviceType: string, section: string, key: string, label: string, sortOrder: int, isTelemetry: bool, isConfigurable: bool, isRequestable: bool, isEvent?: bool}>
     */
    public static function all(): array
    {
        return [
            ['deviceType' => 'ncs', 'section' => 'alarms', 'key' => 'help_call', 'label' => 'Chamada de ajuda', 'sortOrder' => 10, 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],
        ];
    }
}
