<?php

declare(strict_types=1);

namespace Hub\Api\Request;

use Hub\Api\OpenApi\Example;
use Symfony\Component\Validator\Constraints as Assert;

/** O corpo de pedir a descoberta de capacidades de um aparelho. */
final class CapabilityDiscoveryRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'imei is required')]
        #[Example('865028000000306')]
        public ?string $imei = null,
        #[Assert\NotNull(message: 'modelId is required')]
        #[Assert\Positive(message: 'modelId is required')]
        #[Example(1)]
        public ?int $modelId = null,
    ) {
    }
}
