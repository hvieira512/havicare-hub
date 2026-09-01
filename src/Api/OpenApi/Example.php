<?php

declare(strict_types=1);

namespace Hub\Api\OpenApi;

/**
 * O valor de exemplo de um campo, na especificação.
 *
 * Os esquemas escritos à mão traziam um `example` por campo -- um IMEI a sério, `Wonlex`,
 * `HW20PRO` --, e é o que uma página de documentação mostra a quem nunca chamou a rota. A
 * derivação a partir das constraints não tem de onde os tirar: uma constraint diz o que é
 * *válido*, não o que é *ilustrativo*.
 *
 * Fica como atributo no próprio parâmetro, e não numa tabela ao lado, porque uma tabela ao
 * lado é a duplicação que este ficheiro inteiro existe para acabar: o exemplo diverge do
 * campo à primeira renomeação e ninguém dá por isso.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class Example
{
    public function __construct(public readonly mixed $value)
    {
    }
}
