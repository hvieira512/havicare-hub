<?php

declare(strict_types=1);

namespace Hub\Ingress\Http\Qinglanst;

/**
 * `SHA1(segredo#timestamp#pares#)` em maiúsculas, pares `chave=valor` por ordem alfabética.
 * Sem parâmetros a cadeia acaba no timestamp, sem cardinal nenhum.
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
