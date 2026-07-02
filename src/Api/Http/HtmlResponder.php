<?php

namespace Hub\Api\Http;

use React\Http\Message\Response;

final class HtmlResponder
{
    public function respond(string $body): Response
    {
        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }
}
