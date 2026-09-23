<?php

declare(strict_types=1);

namespace Hub\Api\Services;

use Hub\Api\Http\ApiError;
use Hub\Log\Logger;

/**
 * Regista uma escrita de metadados recusada, e devolve a recusa.
 *
 * Junta a forma que cada rejeição do `update()` repete -- o `request_id`, o IMEI, o código e
 * a razão -- para o trabalho real não ficar enterrado na instrumentação. Construído por
 * pedido, porque o `request_id` e o IMEI são dele.
 */
final class DeviceWriteAudit
{
    public function __construct(
        private readonly string $imei,
        private readonly string $requestId,
    ) {
    }

    /**
     * @param array<string, mixed> $extra o que só esta recusa em concreto sabe
     *
     * @return array{error: array<string, mixed>}
     */
    public function reject(ApiError $error, string $reason = '', array $extra = []): array
    {
        $this->log($error->code, $reason, $extra);

        return $error->toArray();
    }

    /**
     * A recusa que o validador já montou como array, e que por isso não tem `ApiError`.
     *
     * @param array<string, mixed> $rejection
     *
     * @return array<string, mixed>
     */
    public function rejectValidated(array $rejection, string $reason): array
    {
        $this->log((string)($rejection['error']['code'] ?? 'invalid_request'), $reason);

        return $rejection;
    }

    /** @param array<string, mixed> $extra */
    private function log(string $code, string $reason, array $extra = []): void
    {
        Logger::channel('api')->warning('API device update rejected', [
            'request_id' => $this->requestId,
            'imei' => $this->imei,
            'error_code' => $code,
        ] + ($reason === '' ? [] : ['reason' => $reason]) + $extra);
    }
}
