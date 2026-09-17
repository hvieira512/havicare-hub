<?php

declare(strict_types=1);

namespace Hub\Api\Request;

use Hub\Api\OpenApi\Example;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * O corpo das credenciais da cloud dos radares de uma licença.
 *
 * A palavra-passe e o segredo nunca saem na resposta, e por isso o ecrã não os pode reenviar
 * ao corrigir o endereço. Vazios querem dizer "fica como está", e a obrigatoriedade só vale
 * quando ainda não há nada guardado -- daí o grupo `create`, como no pedido das licenças.
 */
final class RadarCredentialsWriteRequest
{
    public const GROUP_CREATE = 'create';

    public function __construct(
        #[Assert\NotBlank(message: 'baseUrl is required')]
        #[Assert\Url(message: 'baseUrl must be a URL')]
        #[Assert\Length(max: 255, maxMessage: 'baseUrl must be 255 characters or fewer')]
        #[Example('https://radarconsole.com/prod-api')]
        public ?string $baseUrl = null,
        #[Assert\NotBlank(message: 'username is required')]
        #[Assert\Length(max: 96, maxMessage: 'username must be 96 characters or fewer')]
        #[Example('casabranca')]
        public ?string $username = null,
        #[Assert\NotBlank(message: 'password is required', groups: [self::GROUP_CREATE])]
        #[Assert\Length(max: 255, maxMessage: 'password must be 255 characters or fewer')]
        public ?string $password = null,
        #[Assert\NotBlank(message: 'appId is required')]
        #[Assert\Length(max: 96, maxMessage: 'appId must be 96 characters or fewer')]
        public ?string $appId = null,
        #[Assert\NotBlank(message: 'appSecret is required', groups: [self::GROUP_CREATE])]
        #[Assert\Length(max: 255, maxMessage: 'appSecret must be 255 characters or fewer')]
        public ?string $appSecret = null,
    ) {
    }
}
