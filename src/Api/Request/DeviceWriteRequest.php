<?php

declare(strict_types=1);

namespace Hub\Api\Request;

use Hub\Api\OpenApi\Example;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * O corpo do criar e do actualizar de um dispositivo; o que dele se deriva é domínio e fica no
 * serviço. O `imei` é anulável e vive num grupo porque a actualizar vem do endereço.
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
        /** Sem dono é `null`, com a empresa; o `0` não quer dizer nada. */
        #[Assert\Positive(message: 'licenseId must be a positive integer or null')]
        #[Example(1001)]
        public ?int $licenseId = null,
        #[Example('+351912345678')]
        public string $simNumber = '',
        #[Example('8800000015')]
        public string $deviceId = '',
        /** Vai com o `licenseId`: os dois, ou nenhum. */
        #[Assert\NotBlank(message: 'company must be a name or null', allowNull: true, normalizer: 'trim')]
        #[Assert\Regex(pattern: '/^\s*null\s*$/i', match: false, message: 'company must be a name or null')]
        #[Example('hitcare')]
        public ?string $company = null,
    ) {
    }
}
