<?php

declare(strict_types=1);

namespace Hub\Api\OpenApi;

/** Construtores de corpos de pedido OpenAPI, partilhados pelas definições de rotas. */
final class Requests
{
    /** @return array<string, mixed> */
    public static function json(string $schema): array
    {
        return self::content(['application/json' => ['schema' => Responses::ref($schema)]]);
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public static function inline(array $schema): array
    {
        return self::content(['application/json' => ['schema' => $schema]]);
    }

    /**
     * Endpoints que aceitam o mesmo esquema como upload multipart ou como JSON.
     *
     * @return array<string, mixed>
     */
    public static function multipartOrJson(string $schema): array
    {
        return self::content([
            'multipart/form-data' => ['schema' => Responses::ref($schema)],
            'application/json' => ['schema' => Responses::ref($schema)],
        ]);
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    private static function content(array $content): array
    {
        return ['required' => true, 'content' => $content];
    }
}
