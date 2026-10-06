<?php

declare(strict_types=1);

namespace Hub\Api\Request;

use Hub\Api\OpenApi\Example;
use Symfony\Component\Validator\Constraints as Assert;

/** O corpo de marcar notificações como lidas. */
final class NotificationReadRequest
{
    /**
     * @param list<int>|null $ids
     */
    public function __construct(
        #[Assert\NotNull(message: 'ids array is required')]
        #[Assert\Count(min: 1, minMessage: 'ids array is required')]
        #[Assert\All([new Assert\Positive(message: 'ids must contain positive integers')])]
        #[Example([1, 2])]
        public ?array $ids = null,
    ) {
    }
}
