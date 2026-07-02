<?php

namespace Hub\Api\Http;

use Psr\Http\Message\ServerRequestInterface;

final class RequestBodyReader
{
    public function read(ServerRequestInterface $request): string
    {
        return RequestContext::requestBody($request);
    }
}
