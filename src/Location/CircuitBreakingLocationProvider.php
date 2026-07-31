<?php

namespace Hub\Location;

use React\Promise\PromiseInterface;

use function React\Promise\reject;

final class CircuitBreakingLocationProvider implements LocationProviderContract
{
    public function __construct(
        private readonly LocationProviderContract $provider,
        private readonly ProviderCircuitStateStoreContract $stateStore,
        private readonly int $failureThreshold = 3,
        private readonly int $openSeconds = 300,
        private readonly int $rateLimitOpenSeconds = 3600,
    ) {
        if ($this->failureThreshold < 1) {
            throw new \InvalidArgumentException('Circuit failure threshold must be at least one');
        }
    }

    public function name(): string
    {
        return $this->provider->name();
    }

    public function resolve(array $request): PromiseInterface
    {
        $now = time();
        $state = $this->readState();
        if ($state['openUntil'] > $now) {
            $remaining = $state['openUntil'] - $now;
            return reject(new LocationProviderException(
                "Location provider {$this->name()} circuit is open for {$remaining}s",
                $this->name(),
                retryAfterSeconds: $remaining,
            ));
        }
        if ($state['openUntil'] > 0) {
            $this->clearState();
            $state = ['consecutiveFailures' => 0, 'openUntil' => 0];
        }

        try {
            $promise = $this->provider->resolve($request);
        } catch (\Throwable $error) {
            $this->recordFailure($error);
            return reject($error);
        }

        return $promise->then(
            function (array $response): array {
                $this->clearState();
                return $response;
            },
            function ($error) {
                $throwable = $error instanceof \Throwable
                    ? $error
                    : new \RuntimeException((string)$error);
                $this->recordFailure($throwable);
                throw $throwable;
            },
        );
    }

    private function recordFailure(\Throwable $error): void
    {
        if ($error instanceof LocationProviderException && !$error->retryable) {
            $this->clearState();
            return;
        }

        $failures = $this->readState()['consecutiveFailures'] + 1;
        $openFor = 0;
        if ($error instanceof LocationProviderException && $error->httpStatus === 429) {
            $openFor = max(1, $error->retryAfterSeconds ?? $this->rateLimitOpenSeconds);
        } elseif ($failures >= $this->failureThreshold) {
            $openFor = max(1, $this->openSeconds);
        }

        $newState = [
            'consecutiveFailures' => $failures,
            'openUntil' => $openFor > 0 ? time() + $openFor : 0,
        ];
        $this->writeState($newState, max(3600, $openFor + 60));
    }

    /** @return array{consecutiveFailures: int, openUntil: int} */
    private function readState(): array
    {
        try {
            return $this->stateStore->get($this->name());
        } catch (\Throwable $error) {
            $this->logStoreFailure('read', $error);
            return ['consecutiveFailures' => 0, 'openUntil' => 0];
        }
    }

    /** @param array{consecutiveFailures: int, openUntil: int} $state */
    private function writeState(array $state, int $ttlSeconds): void
    {
        try {
            $this->stateStore->put($this->name(), $state, $ttlSeconds);
        } catch (\Throwable $error) {
            $this->logStoreFailure('write', $error);
        }
    }

    private function clearState(): void
    {
        try {
            $this->stateStore->clear($this->name());
        } catch (\Throwable $error) {
            $this->logStoreFailure('clear', $error);
        }
    }

    private function logStoreFailure(string $operation, \Throwable $error): void
    {
        \Hub\Log\Logger::channel('hub')->warning(
            "Location circuit state {$operation} failed provider={$this->name()}: {$error->getMessage()}"
        );
    }
}
