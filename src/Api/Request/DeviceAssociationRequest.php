<?php

declare(strict_types=1);

namespace Hub\Api\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * O corpo que prende um dispositivo a uma empresa e a uma licença.
 *
 * As duas mensagens são a mesma de propósito. O serviço recusava os dois campos com um texto
 * só -- `company and licenseId are required` --, e é esse texto que os clientes conhecem e
 * que a especificação promete. Agora aparece por campo em vez de uma vez, mas palavra por
 * palavra o mesmo.
 *
 * O `licenseId` é inteiro aqui e a rota manda convertê-lo a partir de texto: sempre foi
 * aceite como `"1001"` -- é o que os clientes mandam e o que os testes escrevem -- e o
 * inteiro é a forma canónica com que o controlo de acesso por cliente o compara.
 */
final class DeviceAssociationRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'company and licenseId are required')]
        #[Assert\Length(max: 191)]
        public string $company = '',
        #[Assert\Positive(message: 'company and licenseId are required')]
        public int $licenseId = 0,
    ) {
    }
}
