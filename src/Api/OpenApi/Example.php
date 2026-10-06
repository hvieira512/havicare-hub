<?php

declare(strict_types=1);

namespace Hub\Api\OpenApi;

/**
 * O valor de exemplo de um campo, na especificação: uma constraint diz o que é *válido*, não o
 * que é *ilustrativo*.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class Example
{
    public function __construct(public readonly mixed $value)
    {
    }
}
