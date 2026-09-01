<?php

declare(strict_types=1);

namespace Hub\Api\OpenApi;

/**
 * O valor de exemplo de um campo, na especificação. A derivação a partir das constraints não
 * o tem: uma constraint diz o que é *válido*, não o que é *ilustrativo*.
 *
 * Fica como atributo no próprio parâmetro e não numa tabela ao lado, que divergiria do campo
 * à primeira renomeação.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class Example
{
    public function __construct(public readonly mixed $value)
    {
    }
}
