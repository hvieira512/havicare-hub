<?php

declare(strict_types=1);

namespace Hub\Api\OpenApi;

use Hub\Api\Http\ApiError;

/** As formas de resposta partilhadas pelas definições de rotas. */
final class Responses
{
    /** @return array<string, string> */
    public static function ref(string $schema): array
    {
        return ['$ref' => '#/components/schemas/' . $schema];
    }

    /**
     * O payload partilhado `components/responses/Error`.
     *
     * @return array<string, string>
     */
    public static function error(): array
    {
        return ['$ref' => '#/components/responses/Error'];
    }

    /**
     * O mapa de respostas de uma rota: o sucesso, mais os erros dos **códigos** que ela pode
     * devolver, com o estado tirado do `ApiError`. Junta-se com `+` porque as chaves são estados HTTP.
     *
     * @param array<array-key, mixed> $success as respostas de sucesso, já com o seu estado
     * @return array<array-key, mixed>
     */
    public static function map(array $success, string ...$codes): array
    {
        $errors = [];
        foreach ($codes as $code) {
            $errors[(string)ApiError::declaredStatus($code)] = self::error();
        }
        ksort($errors);

        return $success + $errors;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public static function content(string $description, array $schema, string $mediaType = 'application/json'): array
    {
        return [
            'description' => $description,
            'content' => [$mediaType => ['schema' => $schema]],
        ];
    }

    /** @return array<string, mixed> */
    public static function json(string $description, string $schema): array
    {
        return self::content($description, self::ref($schema));
    }
}
