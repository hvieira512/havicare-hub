<?php

namespace Hub\Api\Auth;

use Psr\Http\Message\ServerRequestInterface;

final class BearerTokenResolver
{
    public function __construct(private ApiTokenStore $tokens)
    {
    }

    /**
     * A credencial vem no cabeçalho, e só no cabeçalho: nenhum parâmetro do URL autentica,
     * porque um URL fica escrito no registo de qualquer proxy e no histórico do browser.
     */
    public function resolve(ServerRequestInterface $request): ?ApiAuthContext
    {
        $header = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return null;
        }

        return $this->tokens->context((string)$matches[1]);
    }
}
