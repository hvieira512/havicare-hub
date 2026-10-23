<?php

declare(strict_types=1);

namespace Hub\Command\Configuration\Definition;

/** Uma entrada do catálogo de configuração de um protocolo; o texto que a acompanha é da dashboard. */
final class ConfigurationDefinition
{
    /**
     * @param list<string> $fields
     * @param list<string> $expectedReplyTypes
     * @param array<string, mixed>|null $options
     * @param array{on: string, off: string}|null $actions os verbos de uma acção com dois sentidos
     * @param array<string, array{min: int, max?: int}>|null $bounds
     * @param string|null $capability a chave genérica, quando não é a própria `key`
     * @return array<string, mixed>
     */
    public static function make(
        string $key,
        string $command,
        string $input,
        array $fields,
        array $expectedReplyTypes = [],
        string $category = 'general',
        int $order = 0,
        ?int $limit = null,
        ?array $options = null,
        bool $transient = false,
        bool $destructive = false,
        ?string $verb = null,
        ?array $actions = null,
        ?array $bounds = null,
        ?string $capability = null,
    ): array {
        // Transiente e acção são a mesma coisa: o que a `PATCH` recusa é o que a `/requests` aceita.
        return array_filter([
            'key' => $key,
            'command' => $command,
            'kind' => $transient ? 'request' : 'config',
            'risk' => $destructive ? 'destructive' : 'normal',
            'input' => $input,
            'fields' => $fields,
            'expectedReplyTypes' => $expectedReplyTypes,
            'category' => $category,
            'order' => $order,
            'limit' => $limit,
            'options' => $options,
            'transient' => $transient ? true : null,
            'verb' => $verb,
            'actions' => $actions,
            'bounds' => $bounds,
            // Fica em último: é o campo que a API acrescenta a cada entrada do catálogo.
            'capabilityKey' => $capability,
        ], static fn(mixed $value): bool => $value !== null);
    }
}
