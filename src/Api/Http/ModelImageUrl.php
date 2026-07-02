<?php

namespace Hub\Api\Http;

final class ModelImageUrl
{
    public function resolve(string $path, string $baseUrl): ?string
    {
        return $path !== '' ? $baseUrl . $path : null;
    }
}
