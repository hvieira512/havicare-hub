<?php

declare(strict_types=1);

namespace Hub\Api\Services;

use Hub\Api\Http\ApiError;
use Hub\Api\Repository\ApiDataAccess;

final class DenylistService
{
    public function __construct(private ApiDataAccess $db)
    {
    }

    public function list(): array
    {
        return ['data' => $this->db->denylist->all()];
    }

    public function block(array $payload, string $createdBy = ''): array
    {
        $identity = trim((string)($payload['identity'] ?? ''));
        if ($identity === '') {
            return ApiError::invalidRequest('identity is required')->toArray();
        }

        $protocol = trim((string)($payload['protocol'] ?? ''));
        $note = isset($payload['note']) && trim((string)$payload['note']) !== ''
            ? trim((string)$payload['note'])
            : null;

        $this->db->denylist->add($identity, $protocol, $note, $createdBy);
        // Bloquear cala o aparelho de vez: as notificações que ele já gerou desaparecem.
        $cleared = $this->db->dashboardNotifications->deleteByImei($identity);

        return [
            'status' => 'ok',
            'identity' => $identity,
            'clearedNotifications' => $cleared,
        ];
    }

    public function unblock(string $identity): array
    {
        $identity = trim($identity);
        if ($identity === '') {
            return ApiError::invalidRequest('identity is required')->toArray();
        }
        if (!$this->db->denylist->remove($identity)) {
            return ApiError::denylistNotFound()->toArray();
        }

        return ['status' => 'ok', 'identity' => $identity];
    }
}
