<?php

declare(strict_types=1);

namespace Hub\Api\Http;

use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * O cookie da sessão da dashboard, com o token de renovação: `HttpOnly`, `SameSite=Strict`, e o
 * `Path` preso às rotas de autenticação, para o resto da API continuar no `Authorization`.
 */
final class SessionCookie
{
    public const NAME = 'hub_session';
    private const PATH = '/api/auth';

    public static function read(ServerRequestInterface $request): string
    {
        // Lê-se o cabeçalho e não o `getCookieParams()`: nem toda a implementação de PSR-7
        // o preenche, e este é o mesmo código no servidor e nos testes.
        foreach (explode(';', $request->getHeaderLine('Cookie')) as $pair) {
            [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            if ($name === self::NAME) {
                return urldecode($value);
            }
        }

        return '';
    }

    public static function issue(
        Response $response,
        ServerRequestInterface $request,
        string $value,
        int $ttlSeconds
    ): Response {
        return $response->withHeader('Set-Cookie', self::serialize($request, $value, max(1, $ttlSeconds)));
    }

    public static function clear(
        Response $response,
        ServerRequestInterface $request
    ): Response {
        return $response->withHeader('Set-Cookie', self::serialize($request, '', 0));
    }

    private static function serialize(ServerRequestInterface $request, string $value, int $maxAge): string
    {
        $attributes = [
            self::NAME . '=' . urlencode($value),
            'Path=' . self::PATH,
            'Max-Age=' . $maxAge,
            'HttpOnly',
            'SameSite=Strict',
        ];

        if (self::isHttps($request)) {
            $attributes[] = 'Secure';
        }

        return implode('; ', $attributes);
    }

    /** O `Secure` só entra em HTTPS, para o hub local em `http://` guardar o cookie. */
    private static function isHttps(ServerRequestInterface $request): bool
    {
        return $request->getUri()->getScheme() === 'https'
            || strtolower($request->getHeaderLine('X-Forwarded-Proto')) === 'https';
    }
}
