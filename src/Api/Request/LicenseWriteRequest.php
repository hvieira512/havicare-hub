<?php

declare(strict_types=1);

namespace Hub\Api\Request;

use Hub\Api\OpenApi\Example;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * O corpo do criar e do actualizar de uma licença.
 *
 * Os campos são anuláveis porque `null` quer dizer "não veio no corpo", e no actualizar isso
 * quer dizer "fica como está". A omissão só é recusada a criar, e por isso o `NotNull` vive
 * no grupo `create`; o `Positive` vale nos dois.
 */
final class LicenseWriteRequest
{
    public const GROUP_CREATE = 'create';

    public function __construct(
        #[Assert\NotNull(message: 'companyId is required', groups: [self::GROUP_CREATE])]
        #[Assert\Positive(message: 'companyId is required')]
        #[Example(1)]
        public ?int $companyId = null,
        #[Assert\NotNull(message: 'licenseId is required', groups: [self::GROUP_CREATE])]
        #[Assert\Positive(message: 'licenseId is required')]
        #[Example(1001)]
        public ?int $licenseId = null,
        #[Assert\Length(max: 191, maxMessage: 'name must be 191 characters or fewer')]
        #[Example('gucc.dev')]
        public ?string $name = null,
    ) {
    }
}
