<?php

declare(strict_types=1);

namespace Hub\Api\Request;

use Hub\Api\OpenApi\Example;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * O corpo do criar e do actualizar de um modelo, em multipart, onde tudo é string. Os arrays
 * são `null` por omissão porque `[]` quer dizer "nenhuma capacidade".
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
     * Se o pedido tomou posição sobre as capacidades: mandou a lista, ou o `capabilitiesConfigured`
     * diz que a lista vazia é deliberada.
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
