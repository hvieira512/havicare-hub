<?php

namespace Hub\Location;

use React\Promise\PromiseInterface;

final class SequentialLocationProvider implements LocationProviderContract
{
    public function __construct(
        private readonly LocationProviderContract $primary,
        private readonly LocationProviderContract $fallback,
        private readonly LocationResponseValidator $validator,
    ) {
    }

    public function name(): string
    {
        return $this->primary->name() . '+' . $this->fallback->name();
    }

    public function resolve(array $request): PromiseInterface
    {
        $startedAt = hrtime(true);
        return $this->primary->resolve($request)->then(
            function (array $response) use ($request, $startedAt): array|PromiseInterface {
                if ($this->validator->isTrusted($response)) {
                    return $response;
                }
                $this->logFallback('untrusted_response', $startedAt);
                return $this->fallback->resolve($request);
            },
            function ($error) use ($request, $startedAt): PromiseInterface {
                $throwable = $error instanceof \Throwable ? $error : new \RuntimeException((string)$error);
                if (!$this->shouldFallback($throwable)) {
                    throw $throwable;
                }
                $status = $throwable instanceof LocationProviderException
                    ? ($throwable->httpStatus === null ? 'transport' : 'http_' . $throwable->httpStatus)
                    : 'provider_error';
                $this->logFallback($status, $startedAt);
                return $this->fallback->resolve($request);
            },
        );
    }

    private function shouldFallback(\Throwable $error): bool
    {
        if (!$error instanceof LocationProviderException) {
            return true;
        }
        return $error->httpStatus === null
            || $error->httpStatus === 404
            || $error->httpStatus === 429
            || $error->httpStatus >= 500;
    }

    private function logFallback(string $reason, int $startedAt): void
    {
        $durationMs = round((hrtime(true) - $startedAt) / 1_000_000, 2);
        \Hub\Log\Logger::channel('hub')->info(
            "Location provider fallback primary={$this->primary->name()} fallback={$this->fallback->name()} reason={$reason} primary_ms={$durationMs}"
        );
    }
}
