<?php

declare(strict_types=1);

namespace Hub\Api\Request;

use Hub\Api\OpenApi\Example;
use Hub\Domain\DeviceMetadata;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * O corpo do criar e do actualizar de uma empresa.
 *
 * A regra vive aqui e não no serviço porque tem de olhar para o que o cliente escreveu, antes
 * de o `normalizeCompany()` lhe dar um nome que ele não pediu -- esse nunca devolve vazio,
 * devolve `'null'`, que é o nome dos dispositivos sem empresa.
 *
 * O `normalizer: 'trim'` porque um nome só de espaços é um nome em falta.
 */
final class CompanyWriteRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'name is required', normalizer: 'trim')]
        #[Assert\Length(max: 191, maxMessage: 'name must be 191 characters or fewer')]
        #[Example('hitcare')]
        public string $name = '',
    ) {
    }

    /** O nome como a base o guarda: em minúsculas e sem espaços à volta. */
    public function normalizedName(): string
    {
        return DeviceMetadata::normalizeCompany($this->name);
    }
}
