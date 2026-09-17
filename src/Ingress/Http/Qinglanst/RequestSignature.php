<?php

namespace Hub\Ingress\Http\Qinglanst;

/**
 * A assinatura que o fabricante exige em cada pedido: `SHA1(segredo#timestamp#pares#)`, em
 * maiúsculas, com os pares `chave=valor` por ordem alfabética e um cardinal a fechar.
 *
 * Sem parâmetros a cadeia acaba no timestamp, sem cardinal nenhum -- e é a diferença entre
 * passar e levar um 401 sem explicação.
 */
final class RequestSignature
{
    /** @param array<string, scalar> $params */
    public static function create(string $appSecret, int $timestamp, array $params): string
    {
        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }
        sort($pairs);

        $serialized = $pairs === [] ? '' : implode('#', $pairs) . '#';

        return strtoupper(sha1($appSecret . '#' . $timestamp . '#' . $serialized));
    }
}
