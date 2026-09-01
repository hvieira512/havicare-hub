<?php

declare(strict_types=1);

namespace Hub\Api\Request;

use Hub\Api\OpenApi\Example;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * O corpo que prende um dispositivo a uma empresa e a uma licença.
 *
 * As duas mensagens são a mesma de propósito: `company and licenseId are required` é o texto
 * que os clientes conhecem e que a especificação promete.
 *
 * O `licenseId` é inteiro, e a rota converte-o a partir de texto porque `"1001"` é o que os
 * clientes mandam.
 */
final class DeviceAssociationRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'company and licenseId are required')]
        #[Assert\Length(max: 191, maxMessage: 'company must be 191 characters or fewer')]
        #[Example('hitcare')]
        public string $company = '',
        #[Assert\Positive(message: 'company and licenseId are required')]
        #[Example(1001)]
        public int $licenseId = 0,
    ) {
    }
}
