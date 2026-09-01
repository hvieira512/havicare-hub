<?php

namespace Hub\Api\Auth;

use Psr\Http\Message\ServerRequestInterface;

final class BearerTokenResolver
{
    public function __construct(private ApiTokenStore $tokens)
    {
    }

    public function resolve(ServerRequestInterface $request): ?ApiAuthContext
    {
        $header = $request->getHeaderLine('Authorization');
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return $this->tokens->context((string)$matches[1]);
        }

        parse_str((string)$request->getUri()->getQuery(), $params);

        // O `EventSource` não deixa pôr cabeçalhos, e a credencial de um stream tem de vir no
        // URL -- onde fica no registo de qualquer proxy e no histórico do browser. O bilhete
        // vale segundos e uma ligação só, e é o único parâmetro aceite: o token de acesso
        // vale uma hora e serve a API toda.
        $ticket = trim((string)($params['ticket'] ?? ''));
        if ($ticket === '') {
            return null;
        }

        return $this->tokens->consumeStreamTicket($ticket);
    }
}
