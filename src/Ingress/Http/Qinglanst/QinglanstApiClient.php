<?php

declare(strict_types=1);

namespace Hub\Ingress\Http\Qinglanst;

use Psr\Http\Message\ResponseInterface;
use React\Http\Browser;
use React\Http\Message\ResponseException;
use React\Promise\PromiseInterface;

/**
 * A cloud dos radares, por HTTP assíncrono. Endereço e credenciais vêm por parâmetro por serem da
 * licença: com as de outra, os radares respondem `777` mesmo a publicar. Sem `final` para os testes.
 */
class QinglanstApiClient
{
    private Browser $browser;

    public function __construct(Browser $browser, float $timeoutSeconds = 15.0)
    {
        $this->browser = $browser
            ->withTimeout(max(1.0, $timeoutSeconds))
            ->withResponseBuffer(1024 * 1024);
    }

    /**
     * @param array<string, string> $credentials `base_url`, `username`, `password`
     * @return PromiseInterface<array{access_token: string, refresh_token: string, token_type: string, expires_in: int}>
     */
    public function login(array $credentials): PromiseInterface
    {
        $body = json_encode([
            'username' => $credentials['username'],
            'password' => $credentials['password'],
            'pattern' => 'monitor',
            'grantType' => 'password',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $this->browser
            ->post($this->url($credentials, '/login'), ['Content-Type' => 'application/json'], $body)
            ->then(
                static function (ResponseInterface $response): array {
                    $decoded = json_decode((string)$response->getBody(), true);
                    $token = is_array($decoded) ? ($decoded['data']['access_token'] ?? null) : null;
                    if (!is_string($token) || $token === '') {
                        throw new QinglanstApiException('Qinglanst login returned no access token');
                    }

                    return [
                        'access_token' => $token,
                        'refresh_token' => (string)($decoded['data']['refresh_token'] ?? ''),
                        'token_type' => (string)($decoded['data']['token_type'] ?? 'bearer'),
                        'expires_in' => (int)($decoded['data']['expires_in'] ?? 3600),
                    ];
                },
                static fn(mixed $error) => throw self::failure('Qinglanst login failed', $error),
            );
    }

    /**
     * As propriedades do radar, com o `code` tal como veio: um `777` é o radar desligado, e não
     * uma avaria.
     *
     * @param array<string, string> $credentials `base_url`, `app_id`, `app_secret`
     * @param array{access_token: string, token_type: string} $token
     * @return PromiseInterface<array<string, mixed>>
     */
    public function deviceProp(array $credentials, array $token, string $uid): PromiseInterface
    {
        return $this->call($credentials, $token, 'GET', '/thirdparty/v2/deviceProp', ['uid' => $uid]);
    }

    /**
     * Uma escrita da API: o corpo vai em JSON e assina-se como os parâmetros de um GET.
     *
     * @param array<string, string> $credentials
     * @param array{access_token: string, token_type: string} $token
     * @param array<string, scalar> $body
     * @return PromiseInterface<array<string, mixed>>
     */
    public function post(array $credentials, array $token, string $path, array $body): PromiseInterface
    {
        return $this->call($credentials, $token, 'POST', $path, $body);
    }

    /**
     * @param array<string, string> $credentials
     * @param array{access_token: string, token_type: string} $token
     * @param array<string, scalar> $params
     * @return PromiseInterface<array<string, mixed>>
     */
    private function call(array $credentials, array $token, string $method, string $path, array $params): PromiseInterface
    {
        $timestamp = time();
        $headers = [
            'appid' => $credentials['app_id'],
            'timestamp' => (string)$timestamp,
            'signature' => RequestSignature::create($credentials['app_secret'], $timestamp, $params),
            'Authorization' => ucfirst($token['token_type']) . ' ' . $token['access_token'],
            'Content-Type' => 'application/json',
        ];
        $url = $this->url($credentials, $path);
        $request = $method === 'GET'
            ? $this->browser->get($url . '?' . http_build_query($params), $headers)
            : $this->browser->post($url, $headers, json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $request->then(
            static function (ResponseInterface $response) use ($path): array {
                $decoded = json_decode((string)$response->getBody(), true);
                if (!is_array($decoded)) {
                    throw new QinglanstApiException("Qinglanst returned a non-JSON response to {$path}");
                }

                return $decoded;
            },
            static fn(mixed $error) => throw self::failure("Qinglanst {$path} failed", $error),
        );
    }

    /** @param array<string, string> $credentials */
    private function url(array $credentials, string $path): string
    {
        return rtrim($credentials['base_url'], '/') . $path;
    }

    private static function failure(string $message, mixed $error): QinglanstApiException
    {
        if ($error instanceof ResponseException) {
            $status = $error->getResponse()->getStatusCode();

            return new QinglanstApiException("{$message} (HTTP {$status})", 0, $error);
        }

        $throwable = $error instanceof \Throwable ? $error : new \RuntimeException((string)$error);

        return new QinglanstApiException($message . ': ' . $throwable->getMessage(), 0, $throwable);
    }
}
