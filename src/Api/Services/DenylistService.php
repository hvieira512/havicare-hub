<?php

declare(strict_types=1);

namespace Hub\Api\Services;

use Hub\Api\Http\ApiError;
use Hub\Api\Http\CollectionPresenter;
use Hub\Api\Http\CollectionColumns;
use Hub\Api\Request\DenylistBlockRequest;
use Hub\Api\Request\RequestBinder;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;

final class DenylistService
{
    private CollectionPresenter $presenter;

    public function __construct(private ApiDataAccess $db)
    {
        $this->presenter = new CollectionPresenter();
    }

    /** @return array<string, mixed> */
    public function list(string $query = ''): array
    {
        return $this->presenter->present(
            array_map(static fn (array $row): array => [
                'identity' => (string)$row['identity'],
                'protocol' => (string)$row['protocol'],
                'note' => $row['note'],
                'createdBy' => (string)$row['created_by'],
                'createdAt' => (string)$row['created_at'],
            ], $this->db->denylist->all()),
            // Sem escrita: um bloqueio cria-se e remove-se inteiro, e não se edita no sítio.
            new CollectionColumns(
                sortable: ['identity', 'protocol', 'createdAt'],
                textFilters: ['identity' => ['identity', 'note']],
                extra: ['note', 'createdBy'],
            ),
            $query,
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
            return ApiError::invalidField('identity', 'identity is required')->toArray();
        }
        if (!$this->db->denylist->remove($identity)) {
            return ApiError::denylistNotFound()->toArray();
        }

        return ['status' => 'ok', 'identity' => $identity];
    }
}
