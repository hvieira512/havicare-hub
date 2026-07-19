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
    ): array {
        $entry = [
            'key' => $key,
            'command' => $command,
            'label' => $label,
            'kind' => 'config',
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

        return $entry;
    }
}
