<?php

declare(strict_types=1);

namespace Hub\Api\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Que origens podem falar com esta API a partir de um browser.
 *
 * O `*` continua a ser o valor por omissão, e hoje é seguro por uma razão concreta: a
 * autenticação é por `Bearer` em cabeçalho e não por cookie, portanto o browser não anexa
 * credenciais sozinho e não há CSRF a partir de uma página de terceiros.
 *
 * A lista existe porque essa razão não estava declarada em lado nenhum, e a dashboard e a API
 * partilham a porta: no dia em que alguém puser a sessão num cookie, `*` passa a ser um buraco
 * sem ninguém ter tocado neste ficheiro. Com `CORS_ALLOWED_ORIGINS` preenchido, quem responde
 * a essa pergunta é a configuração.
 */
final class CorsPolicy
{
    /** @var list<string> */
    private array $allowed;

    /**
     * @param list<string> $allowedOrigins vazio, ou `*`, mantém a política aberta
     */
    public function __construct(array $allowedOrigins = [])
    {
        $this->allowed = array_values(array_filter(array_map(
            static fn (string $origin): string => rtrim(trim($origin), '/'),
            $allowedOrigins,
        )));
    }

    public function apply(ResponseInterface $response, ?ServerRequestInterface $request = null): ResponseInterface
    {
        $response = $response->withHeader('Access-Control-Allow-Origin', $this->originFor($request))
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization')
            ->withHeader('Access-Control-Max-Age', '86400');

        // A resposta passa a depender do pedido quando a origem é reflectida, e sem isto uma
        // cache pelo meio servia a de outra pessoa.
        return $this->isRestricted() ? $response->withHeader('Vary', 'Origin') : $response;
    }

    /**
     * A origem que o pedido trouxe, quando está na lista; senão a primeira permitida, para a
     * resposta não deixar de ser CORS-válida e um browser de outra origem ver a recusa em vez
     * de um erro de rede sem explicação.
     */
    private function originFor(?ServerRequestInterface $request): string
    {
        if ($this->allowed === [] || in_array('*', $this->allowed, true)) {
            return '*';
        }

        $origin = $request === null ? '' : rtrim(trim($request->getHeaderLine('Origin')), '/');

        return $origin !== '' && in_array($origin, $this->allowed, true) ? $origin : $this->allowed[0];
    }

    /** Se a lista restringe alguma coisa, ou é a política aberta de sempre. */
    public function isRestricted(): bool
    {
        return $this->allowed !== [] && !in_array('*', $this->allowed, true);
    }
}
