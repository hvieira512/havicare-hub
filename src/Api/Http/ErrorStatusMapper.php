<?php

namespace Hub\Api\Http;

final class ErrorStatusMapper
{
    public function map(array $result, int $success = 200): int
    {
        if (!isset($result['error'])) {
            return $success;
        }

        $code = (string)($result['error']['code'] ?? '');
        if ($code === 'forbidden') {
            return 403;
        }
        if ($code === 'not_found' || str_ends_with($code, '_not_found')) {
            return 404;
        }
        if ($code === 'conflict' || str_ends_with($code, '_exists') || str_starts_with($code, 'duplicate_')) {
            return 409;
        }

        return 400;
    }
}
