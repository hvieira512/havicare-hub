<?php

namespace Hub\Command\Configuration\Definition;

final class ConfigurationDefinition
{
    public static function make(
        string $key,
        string $command,
        string $label,
        string $input,
        array $fields,
        array $expectedReplyTypes = [],
        string $category = 'general',
        int $order = 0,
        ?int $limit = null,
        ?array $options = null,
        bool $transient = false,
        string $help = '',
        ?array $actions = null,
    ): array {
        $entry = [
            'key' => $key,
            'command' => $command,
            'label' => $label,
            // Transiente e acção são a mesma coisa: o que a `PATCH` recusa é o que a
            // `/requests` aceita. Declarar as duas em separado deixava-as discordar.
            'kind' => $transient ? 'request' : 'config',
            'risk' => 'normal',
            'input' => $input,
            'fields' => $fields,
            'expectedReplyTypes' => $expectedReplyTypes,
            'category' => $category,
            'order' => $order,
        ];

        if ($limit !== null) {
            $entry['limit'] = $limit;
        }
        if ($options !== null) {
            $entry['options'] = $options;
        }
        if ($transient) {
            $entry['transient'] = true;
        }
        // O que a definição faz ao aparelho, em português. Vive aqui e não no ecrã porque é
        // conhecimento de protocolo, tal como o rótulo: quem sabe que a tendência de tensão
        // mede de dez em dez minutos é quem escreveu o adaptador, não quem desenha o cartão.
        if ($help !== '') {
            $entry['help'] = $help;
        }
        // Os verbos de uma acção com dois sentidos -- «Fazer vibrar» e «Parar». Sem eles o
        // ecrã só sabe oferecer um interruptor e um «Enviar», que não diz o que vai acontecer.
        if ($actions !== null) {
            $entry['actions'] = $actions;
        }

        return $entry;
    }
}
