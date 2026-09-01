<?php

declare(strict_types=1);

namespace Hub\Api\Request;

use Hub\Api\OpenApi\Example;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * O corpo do criar e do actualizar de um modelo.
 *
 * É o pedido difícil dos três, e por isso está aqui: chega em `multipart/form-data` porque
 * traz a imagem, e o multipart não tem tipos -- tudo o que dele sai é string. Um
 * `supplier_id` chega como `"3"` e um `enabled` como `"1"`, e por isso as constraints de
 * tipo estrito que o `ApiUserWriteRequest` usa não servem aqui sem tradução.
 *
 * A distinção entre "não mandou o campo" e "mandou o campo vazio" é o que decide se o
 * actualizar preserva as capacidades que lá estavam ou as substitui por nenhuma. Em PHP isso
 * obriga os arrays a ser `null` por omissão -- `[]` não chegaria, porque é um valor legítimo
 * que quer dizer "nenhuma capacidade".
 */
final class ModelWriteRequest
{
    /**
     * @param list<string>|null $capabilities null = ausente, [] = escolheu nenhuma
     * @param list<string>|null $requestableCapabilities
     */
    public function __construct(
        #[Assert\Positive(message: 'supplier_id, internalModel, and commercialName are required')]
        #[Example(1)]
        public int $supplierId = 0,
        #[Assert\NotBlank(message: 'supplier_id, internalModel, and commercialName are required')]
        #[Assert\Length(max: 191, maxMessage: 'internalModel must be 191 characters or fewer')]
        #[Example('HW20PRO')]
        public string $internalModel = '',
        #[Assert\NotBlank(message: 'supplier_id, internalModel, and commercialName are required')]
        #[Assert\Length(max: 191, maxMessage: 'commercialName must be 191 characters or fewer')]
        #[Example('Wonlex HW20 Pro')]
        public string $commercialName = '',
        #[Example('watch')]
        public string $deviceType = 'watch',
        public ?array $capabilities = null,
        public bool $capabilitiesConfigured = false,
        public ?array $requestableCapabilities = null,
        public bool $requestableCapabilitiesConfigured = false,
    ) {
    }

    /**
     * Se o pedido tomou posição sobre as capacidades.
     *
     * Duas formas de o dizer, e ambas contam. Mandar a lista é uma. A outra é o
     * `capabilitiesConfigured`, que o formulário manda para se poder escolher *nenhuma*: a
     * lista vem vazia e este diz que o vazio é deliberado e não uma omissão. Sem a
     * distinção, desmarcar tudo no painel não se distinguia de não tocar em nada -- e o
     * actualizar repunha as capacidades por omissão do fornecedor.
     */
    public function choseCapabilities(): bool
    {
        return $this->capabilitiesConfigured || $this->capabilities !== null;
    }

    public function choseRequestableCapabilities(): bool
    {
        return $this->requestableCapabilitiesConfigured || $this->requestableCapabilities !== null;
    }
}
