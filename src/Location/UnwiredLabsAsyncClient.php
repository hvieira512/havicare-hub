<?php

namespace Hub\Location;

use Psr\Http\Message\ResponseInterface;
use React\Http\Browser;
use React\Http\Message\ResponseException;
use React\Promise\PromiseInterface;

use function React\Promise\reject;

final class UnwiredLabsAsyncClient implements LocationProviderContract
{
    private Browser $browser;

    public function __construct(
        Browser $browser,
        private readonly UnwiredLabsRequestBuilder $requestBuilder,
        private readonly string $token,
        private readonly string $endpoint = 'https://eu1.unwiredlabs.com/v2/process',
        float $timeoutSeconds = 2.0,
    ) {
        if (trim($this->token) === '' || trim($this->endpoint) === '') {
            throw new \InvalidArgumentException('Unwired Labs token and endpoint are required');
        }
        $this->browser = $browser
            ->withTimeout(max(0.1, $timeoutSeconds))
            ->withResponseBuffer(1024 * 1024);
    }

    public function name(): string
    {
        return 'unwired_labs';
    }

    public function resolve(array $request): PromiseInterface
    {
        $payload = $this->requestBuilder->build($request, $this->token);
        if ($payload === null) {
            return reject(new LocationProviderException(
                'Unwired Labs requires at least two valid Wi-Fi access points',
                $this->name(),
                400,
                false,
            ));
        }
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $this->browser->post($this->endpoint, [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ], $json)->then(function (ResponseInterface $response): array {
            $body = json_decode((string)$response->getBody(), true);
            if (!is_array($body)) {
                throw new LocationProviderException('Unwired Labs returned a non-JSON response', $this->name());
            }
            if (($body['status'] ?? null) !== 'ok') {
                throw $this->apiError((string)($body['message'] ?? 'Unknown API error'));
            }
            if (array_key_exists('aged', $body) || $this->hasFallback($body['fallback'] ?? null)) {
                throw new LocationProviderException(
                    'Unwired Labs returned a degraded location',
                    $this->name(),
                    200,
                    false,
                );
            }

            return [
                'httpStatus' => $response->getStatusCode(),
                'body' => [
                    'location' => ['lat' => $body['lat'] ?? null, 'lng' => $body['lon'] ?? null],
                    'accuracy' => $body['accuracy'] ?? null,
                ],
                'provider' => $this->name(),
            ];
        }, function ($error): void {
            if ($error instanceof ResponseException) {
                $response = $error->getResponse();
                $status = $response->getStatusCode();
                throw new LocationProviderException(
                    "Unwired Labs request failed (HTTP {$status})",
                    $this->name(),
                    $status,
                    $status === 429 || $status >= 500,
                    $this->retryAfterSeconds($response->getHeaderLine('Retry-After')),
                    $error,
                );
            }
            $throwable = $error instanceof \Throwable ? $error : new \RuntimeException((string)$error);
            throw new LocationProviderException(
                'Unwired Labs request failed: ' . $throwable->getMessage(),
                $this->name(),
                previous: $throwable,
            );
        });
    }

    private function apiError(string $message): LocationProviderException
    {
        $normalized = strtolower($message);
        if (str_contains($normalized, 'balance') || str_contains($normalized, 'ratelimited_day')) {
            return new LocationProviderException(
                $message,
                $this->name(),
                429,
                true,
                $this->secondsUntilUtcReset(),
            );
        }
        if (str_contains($normalized, 'ratelimited_minute')) {
            return new LocationProviderException($message, $this->name(), 429, true, 60);
        }
        if (str_contains($normalized, 'ratelimited_second')) {
            return new LocationProviderException($message, $this->name(), 429, true, 1);
        }
        if (str_contains($normalized, 'rate')) {
            return new LocationProviderException($message, $this->name(), 429, true);
        }
        if (str_contains($normalized, 'internal')) {
            return new LocationProviderException($message, $this->name(), 503, true);
        }
        if (str_contains($normalized, 'no match')) {
            return new LocationProviderException($message, $this->name(), 404, false);
        }
        $status = str_contains($normalized, 'token') ? 401 : 400;
        return new LocationProviderException($message, $this->name(), $status, false);
    }

    private function hasFallback(mixed $fallback): bool
    {
        return is_array($fallback) ? $fallback !== [] : trim((string)$fallback) !== '';
    }

    private function retryAfterSeconds(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return max(1, (int)$value);
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? null : max(1, $timestamp - time());
    }

    private function secondsUntilUtcReset(): int
    {
        $nextMidnight = (new \DateTimeImmutable('tomorrow', new \DateTimeZone('UTC')))->getTimestamp();
        return max(1, $nextMidnight - time());
    }
}
