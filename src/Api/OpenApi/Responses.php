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
     * O mapa de respostas de uma rota: o sucesso que ela devolve, mais os erros dos
     * **códigos** que pode devolver.
     *
     * O estado de erro deixa de se escrever aqui. Quem o decide em execução é o `ApiError`, e
     * era a duplicação que apodrecia: o `POST /api/models` prometia 200 e 400 e devolvia
     * também `supplier_not_found`, que é 404; o `PUT /api/models/{id}` omitia o 404 que o
     * `GET` da mesma rota declarava; o `config-catalog` prometia 400 e devolvia 404. Nada
     * confrontava as duas listas porque não havia duas listas -- havia um número escrito à
     * mão de um lado e um construtor do outro.
     *
     * Vários códigos com o mesmo estado colapsam numa entrada só, que é o que o OpenAPI
     * permite e o que a API faz: a resposta tem a mesma forma e é o `code` no corpo que
     * distingue o caso.
     *
     * A junção é com `+` e não com `...`. As chaves são estados HTTP, que o PHP guarda como
     * inteiros, e o desdobramento renumera chaves inteiras -- um `'200' => ...` seguido de
     * `...errors()` dava 201 e 202 em vez de 400 e 404. O `+` preserva-as, e é por isso que
     * compor o mapa é trabalho desta função e não de quem a chama.
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
