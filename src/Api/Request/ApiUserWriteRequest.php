<?php

declare(strict_types=1);

namespace Hub\Api\Request;

use Hub\Api\Auth\ApiAuthContext;
use Hub\Api\OpenApi\Example;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * O corpo do criar e do actualizar de um utilizador da API.
 *
 * As regras estão aqui e não espalhadas pelo serviço, e é a mesma declaração que a
 * especificação OpenAPI lê para descrever o pedido. Antes eram duas: o `fields()` do serviço
 * decidia o que era obrigatório e o `TenancySchemas` dizia-o outra vez à mão -- e já não
 * diziam o mesmo. O esquema escrito à mão declarava o `role` obrigatório, quando o serviço
 * lhe dá `license_client` por omissão, e nem sequer mencionava o `licenseId` nem o
 * `companyId`, que o serviço lê.
 *
 * O `password` só é obrigatório a criar, e por isso vive no grupo `create`. O actualizar
 * corre sem esse grupo e aceita a ausência dele como "não mudar a palavra-passe".
 */
final class ApiUserWriteRequest
{
    public const GROUP_CREATE = 'create';

    public function __construct(
        #[Assert\NotBlank(message: 'username is required')]
        #[Assert\Length(max: 191, maxMessage: 'username must be 191 characters or fewer')]
        #[Example('tenant-1001')]
        public string $username = '',
        #[Assert\NotBlank(message: 'password is required', groups: [self::GROUP_CREATE])]
        public string $password = '',
        #[Assert\Choice(
            callback: [ApiAuthContext::class, 'roles'],
            message: 'role must be hub_admin or license_client',
        )]
        public string $role = ApiAuthContext::ROLE_LICENSE_CLIENT,
        /** A linha exacta de empresa+licença. Obrigatório para o `license_client`. */
        #[Assert\Positive(message: 'licenseRefId must be a positive integer')]
        #[Example(1)]
        public ?int $licenseRefId = null,
        #[Assert\PositiveOrZero(message: 'licenseId must be zero or a positive integer')]
        public int $licenseId = 0,
        #[Assert\PositiveOrZero(message: 'companyId must be zero or a positive integer')]
        public int $companyId = 0,
        public bool $enabled = true,
    ) {
    }

    public function isLicenseClient(): bool
    {
        return $this->role === ApiAuthContext::ROLE_LICENSE_CLIENT;
    }
}
