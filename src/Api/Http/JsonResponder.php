<?php

namespace Hub\Api\Http;

use React\Http\Message\Response;

final class JsonResponder
{
    /**
     * A resposta a um resultado de serviço, com o estado que o próprio resultado declara. É
     * esta que os controllers usam: com o estado decidido fora, esquecer o argumento
     * respondia 200 a um corpo de erro, em silêncio.
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
     * O `JSON_INVALID_UTF8_SUBSTITUTE` impede que um byte inválido vindo de um dispositivo
     * derrube a rota: sem ele, o `json_encode()` devolvia `false` e a listagem inteira saía
     * 500 por causa de um nome estragado.
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
