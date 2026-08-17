<?php

namespace Hub\Api\OpenApi;

/**
 * Reusable OpenAPI request body builders shared by the path definitions.
 */
final class Requests
{
    public static function json(string $schema): array
    {
        return self::content(['application/json' => ['schema' => Responses::ref($schema)]]);
    }

    public static function inline(array $schema): array
    {
        return self::content(['application/json' => ['schema' => $schema]]);
    }

    /**
     * Endpoints that accept the same schema as a multipart upload or as JSON.
     */
    public static function multipartOrJson(string $schema): array
    {
        return self::content([
            'multipart/form-data' => ['schema' => Responses::ref($schema)],
            'application/json' => ['schema' => Responses::ref($schema)],
        ]);
    }

    private static function content(array $content): array
    {
        return ['required' => true, 'content' => $content];
    }
}
