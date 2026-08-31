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
        //
        // O `access_token` também já era aceite aqui, e saiu: nada o punha num URL -- nem a
        // dashboard, nem o simulador, nem os cenários --, e não estava documentado como
        // parâmetro. Enquanto existisse, era o último caminho por onde uma credencial de uma
        // hora, boa para a API toda, podia viajar num endereço. O bilhete é o único que fica.
        $ticket = trim((string)($params['ticket'] ?? ''));
        if ($ticket === '') {
            return null;
        }

        return $this->tokens->consumeStreamTicket($ticket);
    }
}
