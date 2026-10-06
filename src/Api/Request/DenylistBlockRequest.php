<?php

declare(strict_types=1);

namespace Hub\Api\Request;

use Hub\Api\OpenApi\Example;
use Symfony\Component\Validator\Constraints as Assert;

/** O corpo de bloquear uma identidade. */
final class DenylistBlockRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'identity is required')]
        #[Example('357000000000123')]
        public ?string $identity = null,
        #[Example('four-p-touch')]
        public ?string $protocol = null,
        #[Assert\Length(max: 255, maxMessage: 'note must be 255 characters or fewer')]
        public ?string $note = null,
    ) {
    }
}
