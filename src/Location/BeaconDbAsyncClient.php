<?php

namespace Hub\Location;

use Psr\Http\Message\ResponseInterface;
use React\Http\Browser;
use React\Http\Message\ResponseException;
use React\Promise\PromiseInterface;

final class BeaconDbAsyncClient implements LocationProviderContract
{
    private Browser $browser;

    public function __construct(
        Browser $browser,
        private readonly string $endpoint = 'https://api.beacondb.net/v1/geolocate',
        private readonly string $userAgent = 'HaviCare Devices Hub/1.0',
        float $timeoutSeconds = 5.0,
    ) {
        if (trim($this->endpoint) === '') {
            throw new \InvalidArgumentException('BeaconDB endpoint is required');
        }
        if (trim($this->userAgent) === '') {
            throw new \InvalidArgumentException('A descriptive BeaconDB User-Agent is required');
        }

        $this->browser = $browser
            ->withTimeout(max(0.1, $timeoutSeconds))
            ->withResponseBuffer(1024 * 1024);
    }

    public function name(): string
    {
        return 'beacondb';
    }

    /** @return PromiseInterface<array{httpStatus: int, body: array<string, mixed>}> */
    public function resolve(array $request): PromiseInterface
    {
        $json = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $this->browser->post($this->endpoint, [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => $this->userAgent,
        ], $json)->then(static function (ResponseInterface $response): array {
            $status = $response->getStatusCode();
            $body = json_decode((string)$response->getBody(), true);
            if (!is_array($body)) {
                throw new LocationProviderException(
                    "BeaconDB returned non-JSON response (HTTP {$status})",
                    'beacondb',
                    $status,
                );
            }

            return ['httpStatus' => $status, 'body' => $body];
        }, static function ($error): void {
            if ($error instanceof ResponseException) {
                $response = $error->getResponse();
                $status = $response->getStatusCode();
                $retryAfter = self::retryAfterSeconds($response->getHeaderLine('Retry-After'));
                throw new LocationProviderException(
                    "BeaconDB request failed (HTTP {$status})",
                    'beacondb',
                    $status,
                    $status === 429 || $status >= 500,
                    $retryAfter,
                    $error,
                );
            }
            $throwable = $error instanceof \Throwable ? $error : new \RuntimeException((string)$error);
            throw new LocationProviderException(
                'BeaconDB request failed: ' . $throwable->getMessage(),
                'beacondb',
                previous: $throwable,
            );
        });
    }

    private static function retryAfterSeconds(string $value): ?int
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
}
