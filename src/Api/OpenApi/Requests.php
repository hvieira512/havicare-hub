<?php

namespace Hub\Api\OpenApi;

/** Construtores de corpos de pedido OpenAPI, partilhados pelas definições de rotas. */
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

    /** Endpoints que aceitam o mesmo esquema como upload multipart ou como JSON. */
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
