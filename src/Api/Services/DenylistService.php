<?php

declare(strict_types=1);

namespace Hub\Api\Services;

use Hub\Api\Http\ApiError;
use Hub\Api\Http\CollectionPresenter;
use Hub\Api\Http\DenylistColumns;
use Hub\Api\Request\DenylistBlockRequest;
use Hub\Api\Request\RequestBinder;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;

final class DenylistService
{
    private const DEFAULT_COLLECTION_LIMIT = 20;

    private CollectionPresenter $presenter;

    public function __construct(private ApiDataAccess $db)
    {
        $this->presenter = new CollectionPresenter();
    }

    /** @return array<string, mixed> */
    public function list(string $query = ''): array
    {
        return $this->presenter->present(
            $this->db->denylist->all(),
            DenylistColumns::definition(),
            $this->presenter->params($query),
            self::DEFAULT_COLLECTION_LIMIT,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function block(array $payload, string $createdBy = ''): array
    {
        $request = (new RequestBinder())->bind($payload, DenylistBlockRequest::class, coerceStrings: true);
        if (is_array($request)) {
            return $request;
        }

        $identity = trim((string)$request->identity);
        $protocol = trim((string)($request->protocol ?? ''));
        $note = trim((string)($request->note ?? '')) !== '' ? trim((string)$request->note) : null;

        $this->db->denylist->add($identity, $protocol, $note, $createdBy);
        // Bloquear cala o aparelho de vez: as notificações que ele já gerou desaparecem.
        $cleared = $this->db->dashboardNotifications->deleteByImei($identity);

        return [
            'status' => 'ok',
            'identity' => $identity,
            'clearedNotifications' => $cleared,
        ];
    }

    /** @return array<string, mixed> */
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
