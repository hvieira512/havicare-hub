<?php

namespace Hub\Location;

use Psr\Http\Message\ResponseInterface;
use React\Http\Browser;
use React\Promise\PromiseInterface;

final class BeaconDbAsyncClient
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
                throw new \RuntimeException("BeaconDB returned non-JSON response (HTTP {$status})");
            }

            return ['httpStatus' => $status, 'body' => $body];
        });
    }
}
