<?php

namespace Hub\Api\Controllers;

use Hub\Api\Auth\BearerTokenResolver;
use Hub\Api\Http\ApiError;
use Hub\Api\Http\JsonResponder;
use Hub\Api\Http\RequestContext;
use Hub\Api\Http\SessionCookie;
use Hub\Api\Services\AuthService;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

final class AuthController
{
    public function __construct(
        private AuthService $service,
        private JsonResponder $json,
    ) {
    }

    /**
     * O estado era 401 para qualquer erro, e por isso um corpo sem password -- ou que nem
     * sequer era JSON -- saía como "credencial recusada" em vez de "pedido mal formado". A
     * credencial errada continua a responder 401, que é o que o `invalid_credentials` e o
     * `invalid_refresh_token` declaram; o resto passa a responder o que o seu código diz.
     */
    public function login(ServerRequestInterface $request): Response
    {
        $payload = RequestContext::jsonBody($request);
        if ($payload === null) {
            return $this->json->result(ApiError::invalidJson()->toArray());
        }

        // A dashboard pede `session: cookie` e a partir daí não volta a falar de renovação: o
        // token vai e vem no cookie, que o browser reenvia em qualquer separador. Quem integra
        // pela API não pede nada disto e continua a receber o par no corpo.
        $session = SessionCookie::read($request);
        $wantsCookie = $session !== '' || ($payload['session'] ?? '') === 'cookie';

        // O cookie só entra quando o corpo não traz credenciais: escrever a palavra-passe é
        // dizer «esquece a sessão que aí está». O `AuthService` renova antes de olhar para o
        // utilizador, e o ramo da renovação também não passa pelo teto de tentativas.
        $usedSession = $session !== ''
            && trim((string)($payload['refresh_token'] ?? '')) === ''
            && trim((string)($payload['username'] ?? '')) === ''
            && (string)($payload['password'] ?? '') === '';
        if ($usedSession) {
            $payload['refresh_token'] = $session;
        }

        $result = $this->service->login(
            $payload,
            RequestContext::requestId($request),
            // A mesma origem que o registo de pedidos usa, para as duas linhas falarem do
            // mesmo endereço.
            RequestContext::clientAddress($request),
        );

        if (isset($result['error'])) {
            // Um cookie recusado não volta a servir, e por apagar ficava o `Max-Age` inteiro.
            return $usedSession
                ? SessionCookie::clear($this->json->result($result), $request)
                : $this->json->result($result);
        }

        if (!$wantsCookie) {
            return $this->json->result($result);
        }

        $refreshToken = (string)($result['token']['refresh_token'] ?? '');
        $refreshTtl = (int)($result['token']['refresh_expires_in'] ?? 0);
        unset(
            $result['token']['refresh_token'],
            $result['token']['refresh_expires_in'],
            $result['token']['refresh_expires_at'],
        );

        return SessionCookie::issue($this->json->result($result), $request, $refreshToken, $refreshTtl);
    }

    /**
     * Termina a sessão do cookie: apaga-o e queima as duas credenciais que ele representa.
     *
     * A rota é pública porque o cookie é a credencial -- exigir um token de acesso válido
     * deixava um separador com o token expirado sem maneira de fechar a sessão.
     */
    public function logout(ServerRequestInterface $request): Response
    {
        $this->service->logout(
            SessionCookie::read($request),
            BearerTokenResolver::tokenFrom($request),
            RequestContext::requestId($request),
        );

        return SessionCookie::clear($this->json->respond(['status' => 'ok']), $request);
    }

    /** Só administradores chegam aqui: o `RouteAccessPolicy` nega esta rota a toda a gente. */
    public function licenseToken(ServerRequestInterface $request): Response
    {
        $payload = RequestContext::jsonBody($request);

        return $this->json->result($payload === null
            ? ApiError::invalidJson()->toArray()
            : $this->service->licenseToken($payload, RequestContext::requestId($request)));
    }
}
