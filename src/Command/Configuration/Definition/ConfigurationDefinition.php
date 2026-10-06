<?php

declare(strict_types=1);

namespace Hub\Command\Configuration\Definition;

final class ConfigurationDefinition
{
    /**
     * @param list<string> $fields
     * @param list<string> $expectedReplyTypes
     * @param array<string, mixed>|null $options
     * @param array<string, mixed>|null $actions
     * @return array<string, mixed>
     */
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
        string $verb = '',
    ): array {
        $entry = [
            'key' => $key,
            'command' => $command,
            'label' => $label,
            // Transiente e acção são a mesma coisa: o que a `PATCH` recusa é o que a `/requests` aceita.
            'kind' => $transient ? 'request' : 'config',
            // Derivado da confirmação: uma acção que precisa de ser confirmada é destrutiva.
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
        // conhecimento de protocolo, tal como o rótulo.
        if ($help !== '') {
            $entry['help'] = $help;
        }
        // Os verbos de uma acção com dois sentidos, «Fazer vibrar» e «Parar».
        if ($actions !== null) {
            $entry['actions'] = $actions;
        }
        // O que o utilizador autoriza, neste protocolo: o `reset_device` da Wonlex repõe de fábrica
        // e o do 4P Touch reinicia, e por isso a frase não se escolhe pela capacidade.
        if ($confirm !== '') {
            $entry['confirm'] = $confirm;
        }
        // O texto do botão de uma acção. Só se declara onde o rótulo é um nome, como «Reposição de
        // fábrica», que num botão não diz o que o clique faz.
        if ($verb !== '') {
            $entry['verb'] = $verb;
        }

        return $entry;
    }
}
