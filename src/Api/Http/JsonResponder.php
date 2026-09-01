<?php

namespace Hub\Api\Http;

use React\Http\Message\Response;

final class JsonResponder
{
    /**
     * A resposta a um resultado de serviço, com o estado que o próprio resultado declara.
     *
     * É esta que os controllers usam. O estado era decidido fora, por um `ErrorStatusMapper`
     * que quem chamava tinha de se lembrar de consultar e cujo resultado tinha de entregar
     * aqui -- `respond($r, $this->status->map($r))`, trinta vezes. Esquecer o segundo
     * argumento respondia 200 a um corpo de erro, e em silêncio, porque o corpo continuava
     * certo: foi assim que o catálogo de modelos, o detalhe do modelo e o login passaram a
     * mentir no estado. A forma insegura era a mais curta de escrever, e por isso a
     * decisão deixou de ser de quem chama.
     */
    public function result(array $payload, int $success = 200): Response
    {
        return $this->respond($payload, isset($payload['error'])
            ? ApiError::statusForCode((string)($payload['error']['code'] ?? ''))
            : $success);
    }

    /**
     * O estado escrito à mão, para as respostas que não nascem de um resultado de serviço.
     *
     * O `JSON_INVALID_UTF8_SUBSTITUTE` é o que impede um byte inválido de derrubar a rota
     * inteira. O hub normaliza fielmente o que os dispositivos enviam, e um nome, modelo ou
     * empresa com um byte que não é UTF-8 fazia o `json_encode()` devolver `false`; o
     * `Response` do React recusa-o com uma excepção, o `ApiKernel` apanha-a, e a listagem
     * toda saía 500 para toda a gente por causa de um dispositivo. Com a substituição, o
     * carácter estragado vira `U+FFFD` e o resto da resposta chega.
     */
    public function respond(array $payload, int $status = 200): Response
    {
        $body = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return new Response($status, ['Content-Type' => 'application/json'], $body === false ? '{}' : $body);
    }
}
