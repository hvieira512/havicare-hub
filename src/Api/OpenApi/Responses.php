<?php

namespace Hub\Api\OpenApi;

/** As formas de resposta partilhadas pelas definições de rotas. */
final class Responses
{
    public static function ref(string $schema): array
    {
        return ['$ref' => '#/components/schemas/' . $schema];
    }

    /** O payload partilhado `components/responses/Error`. */
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
}
