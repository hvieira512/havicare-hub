<?php

namespace Hub\Api\Http;

use React\Http\Message\Response;

final class JsonResponder
{
    public function respond(array $payload, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
