<?php

namespace Hub\Location;

final class BeaconDbClient
{
    public function __construct(
        private readonly string $endpoint = 'https://api.beacondb.net/v1/geolocate',
        private readonly float $timeoutSeconds = 5.0,
    ) {
    }

    /** @return array{httpStatus: int, body: array<string, mixed>} */
    public function resolve(array $request, string $userAgent): array
    {
        $userAgent = trim($userAgent);
        if ($userAgent === '') {
            throw new \InvalidArgumentException('A descriptive BeaconDB User-Agent is required');
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('The PHP cURL extension is required for the BeaconDB probe');
        }

        $json = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $curl = curl_init($this->endpoint);
        if ($curl === false) {
            throw new \RuntimeException('Unable to initialize cURL');
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => min(2000, (int)round($this->timeoutSeconds * 1000)),
            CURLOPT_TIMEOUT_MS => max(1, (int)round($this->timeoutSeconds * 1000)),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'User-Agent: ' . $userAgent,
            ],
        ]);

        $response = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        if ($response === false) {
            throw new \RuntimeException('BeaconDB request failed: ' . ($error !== '' ? $error : 'unknown cURL error'));
        }

        $body = json_decode($response, true);
        if (!is_array($body)) {
            throw new \RuntimeException("BeaconDB returned non-JSON response (HTTP {$status})");
        }

        return ['httpStatus' => $status, 'body' => $body];
    }
}
