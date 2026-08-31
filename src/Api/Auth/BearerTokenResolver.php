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

        // O `EventSource` não deixa pôr cabeçalhos, e por isso a credencial de um stream tem
        // de vir no URL. O bilhete existe para que o que ali vai valha segundos e uma ligação
        // só, em vez de ser o token de acesso de uma hora -- um URL fica escrito no registo
        // de qualquer proxy pelo caminho e no histórico do browser.
        $ticket = trim((string)($params['ticket'] ?? ''));
        if ($ticket !== '') {
            return $this->tokens->consumeStreamTicket($ticket);
        }

        // O `access_token` no URL continua a servir para não partir quem já o usa, mas a
        // dashboard deixou de o mandar. Não o use em código novo.
        $queryToken = trim((string)($params['access_token'] ?? ''));
        if ($queryToken === '') {
            return null;
        }

        return $this->tokens->context($queryToken);
    }
}
