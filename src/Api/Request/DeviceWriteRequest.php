<?php

declare(strict_types=1);

namespace Hub\Api\Request;

use Hub\Api\OpenApi\Example;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * O corpo do criar e do actualizar de um dispositivo.
 *
 * O objecto descreve o que o cliente manda; o que se *deriva* disso continua no serviço, e é
 * bem mais do que parece: o tipo sai do modelo encontrado no catálogo, a licença normaliza-se
 * em função do tipo, e o `deviceId` em função dos quatro. Essa cadeia é domínio e não forma
 * de pedido, e descê-la para aqui não a tornava mais clara.
 *
 * O `imei` é o único campo cuja regra difere entre as duas rotas: a criar é obrigatório, a
 * actualizar vem do endereço quando o corpo não o traz -- um `PUT` que só queira mudar o
 * fornecedor não repete o IMEI que já está no URL. Daí o grupo, e daí ser anulável: `null`
 * quer dizer "não veio", que é diferente de "veio vazio", e só o segundo é recusa.
 *
 * O `licenseId` é inteiro. Os clientes mandam-no muitas vezes como texto, e continuam a
 * poder: a conversão de strings numéricas faz-se ao ligar o corpo ao objecto, que é a borda,
 * e não alargando o tipo declarado. O inteiro é a forma canónica com que o controlo de acesso
 * por cliente o compara, e é o que a especificação passa a prometer.
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
         * O número da licença é inteiro, e é essa a sua forma.
         *
         * Que os clientes o mandem como `"1001"` é tolerância da borda e não o tipo da coisa:
         * a rota corre com conversão de strings numéricas ligada, e por isso `"1001"` e
         * `1001` entram os dois -- mas `"mil e um"` passa a ser recusado em vez de virar zero,
         * que é uma licença que existe e quer dizer "sem licença".
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
