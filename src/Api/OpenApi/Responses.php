<?php

namespace Hub\Api\OpenApi;

/**
 * Response shapes shared by the path definitions.
 */
final class Responses
{
    public static function ref(string $schema): array
    {
        return ['$ref' => '#/components/schemas/' . $schema];
    }

    /**
     * The shared components/responses/Error payload.
     */
    public static function error(): array
    {
        return ['$ref' => '#/components/responses/Error'];
    }

    public static function content(string $description, array $schema, string $mediaType = 'application/json'): array
    {
        return [
            'description' => $description,
            'content' => [$mediaType => ['schema' => $schema]],
        ];
    }

    public static function json(string $description, string $schema): array
    {
        return self::content($description, self::ref($schema));
    }

    public static function object(string $description): array
    {
        return self::content($description, ['type' => 'object']);
    }
}
