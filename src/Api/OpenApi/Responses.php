<?php

namespace Hub\Api\OpenApi;

use Hub\Api\Http\ApiError;

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

    /**
     * O mapa de respostas de uma rota: o sucesso, mais os erros dos **códigos** que ela pode
     * devolver. O estado de cada código vem do `ApiError` e não se escreve aqui, senão as
     * duas listas divergiam sem nada as confrontar.
     *
     * Vários códigos com o mesmo estado colapsam numa entrada só -- a resposta tem a mesma
     * forma, e é o `code` no corpo que distingue o caso.
     *
     * A junção é com `+` e não com `...`: as chaves são estados HTTP, e o desdobramento
     * renumera chaves inteiras.
     *
     * @param array<string, mixed> $success as respostas de sucesso, já com o seu estado
     * @return array<string, mixed>
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
