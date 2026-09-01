<?php

declare(strict_types=1);

namespace Hub\Api\Request;

use Hub\Api\OpenApi\Example;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * O corpo do criar e do actualizar de um dispositivo. O que daqui se *deriva* -- o tipo a
 * partir do modelo, a licença em função do tipo, o `deviceId` em função dos quatro -- é
 * domínio e fica no serviço.
 *
 * O `imei` é o único campo cuja regra difere entre as duas rotas: a criar é obrigatório, a
 * actualizar vem do endereço. Daí o grupo, e daí ser anulável -- `null` é "não veio", que não
 * é o mesmo que "veio vazio".
 */
final class DeviceWriteRequest
{
    public const GROUP_CREATE = 'create';

    public function __construct(
        #[Assert\NotBlank(
            message: 'imei, supplier, and model are required',
            normalizer: 'trim',
            groups: [self::GROUP_CREATE],
        )]
        #[Example('865028000000306')]
        public ?string $imei = null,
        #[Assert\NotBlank(message: 'imei, supplier, and model are required', normalizer: 'trim')]
        #[Example('Wonlex')]
        public string $supplier = '',
        #[Assert\NotBlank(message: 'imei, supplier, and model are required', normalizer: 'trim')]
        #[Example('HW20PRO')]
        public string $model = '',
        /** Só vale quando o modelo não o disser: o catálogo é que manda no tipo. */
        #[Example('watch')]
        public string $deviceType = 'watch',
        /**
         * Inteiro. Que os clientes o mandem como `"1001"` é tolerância da borda: `"mil e um"`
         * é recusado em vez de virar zero, que quer dizer "sem licença".
         */
        #[Assert\PositiveOrZero(message: 'licenseId must be zero or a positive integer')]
        #[Example(1001)]
        public int $licenseId = 0,
        #[Example('+351912345678')]
        public string $simNumber = '',
        #[Example('8800000015')]
        public string $deviceId = '',
        #[Example('hitcare')]
        public string $company = 'null',
    ) {
    }
}
