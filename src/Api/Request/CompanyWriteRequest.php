<?php

declare(strict_types=1);

namespace Hub\Api\Request;

use Hub\Domain\DeviceMetadata;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * O corpo do criar e do actualizar de uma empresa.
 *
 * A regra vive aqui e não no serviço, e é por isso que passa a existir de facto. No serviço
 * ela estava escrita depois da normalização:
 *
 *     $name = DeviceMetadata::normalizeCompany((string)($payload['name'] ?? ''));
 *     if ($name === '') { return ApiError::invalidRequest('name is required'); }
 *
 * e o `normalizeCompany()` nunca devolve vazio -- devolve `'null'`, que é o nome que os
 * dispositivos sem empresa usam. O `if` era código morto, e um `POST /api/companies` com o
 * corpo vazio criava uma empresa chamada `null` e respondia sucesso. Aqui a constraint olha
 * para o que o cliente escreveu, antes de a normalização lhe dar um nome que ele não pediu.
 *
 * O `normalizer: 'trim'` porque o serviço sempre aparou antes de comparar, e um nome só de
 * espaços é um nome em falta.
 */
final class CompanyWriteRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'name is required', normalizer: 'trim')]
        #[Assert\Length(max: 191, maxMessage: 'name must be 191 characters or fewer')]
        public string $name = '',
    ) {
    }

    /** O nome como a base o guarda: em minúsculas e sem espaços à volta. */
    public function normalizedName(): string
    {
        return DeviceMetadata::normalizeCompany($this->name);
    }
}
