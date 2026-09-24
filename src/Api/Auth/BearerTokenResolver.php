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
        $token = self::tokenFrom($request);

        return $token === '' ? null : $this->tokens->context($token);
    }

    /** O token em cru, para quem precisa dele sem o resolver -- o logout revoga-o. */
    public static function tokenFrom(ServerRequestInterface $request): string
    {
        $header = $request->getHeaderLine('Authorization');

        return preg_match('/^Bearer\s+(.+)$/i', $header, $matches) === 1 ? trim((string)$matches[1]) : '';
    }
}
