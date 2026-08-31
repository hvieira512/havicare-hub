<?php

namespace Hub\Api\Http;

use Psr\Http\Message\ResponseInterface;

final class CorsPolicy
{
    public function apply(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization')
            ->withHeader('Access-Control-Max-Age', '86400');
    }
}
