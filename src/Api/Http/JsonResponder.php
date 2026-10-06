<?php

declare(strict_types=1);

namespace Hub\Api\Http;

use React\Http\Message\Response;

final class JsonResponder
{
    /**
     * A resposta a um resultado de serviço, com o estado que o próprio resultado declara.
     *
     * @param array<string, mixed> $payload
     */
    public function result(array $payload, int $success = 200): Response
    {
        return $this->respond($payload, isset($payload['error'])
            ? ApiError::statusForCode((string)($payload['error']['code'] ?? ''))
            : $success);
    }

    /**
     * O estado escrito à mão, para as respostas que não nascem de um resultado de serviço. O
     * `JSON_INVALID_UTF8_SUBSTITUTE` impede que um byte inválido de um dispositivo derrube a rota.
     *
     * @param array<string, mixed> $payload
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
