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
        string $confirm = '',
    ): array {
        $entry = [
            'key' => $key,
            'command' => $command,
            'label' => $label,
            // Transiente e acção são a mesma coisa: o que a `PATCH` recusa é o que a
            // `/requests` aceita. Declarar as duas em separado deixava-as discordar.
            'kind' => $transient ? 'request' : 'config',
            // Derivado da confirmação, e não declarado à parte: uma acção que precisa de ser
            // confirmada é destrutiva, e dois campos independentes acabam a discordar.
            'risk' => $confirm === '' ? 'normal' : 'destructive',
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
        // O que o utilizador está a autorizar, na linguagem do que acontece ao aparelho neste
        // protocolo. A mesma capacidade não quer dizer o mesmo em todos: o `reset_device` da
        // Wonlex repõe de fábrica e o do 4P Touch reinicia. Quem escolhe a frase pela chave
        // da capacidade acaba a prometer um reinício a quem está a apagar o aparelho.
        if ($confirm !== '') {
            $entry['confirm'] = $confirm;
        }

        return $entry;
    }
}
