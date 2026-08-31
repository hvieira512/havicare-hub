<?php

namespace Hub\Api\Http;

/**
 * O estado HTTP de um resultado de serviço.
 *
 * Continua a receber o array porque é isso que os serviços devolvem, mas já não adivinha o
 * estado pela forma do código: pergunta-o ao `ApiError`, onde cada código o declara.
 */
final class ErrorStatusMapper
{
    public function map(array $result, int $success = 200): int
    {
        if (!isset($result['error'])) {
            return $success;
        }

        return ApiError::statusForCode((string)($result['error']['code'] ?? ''));
    }
}
