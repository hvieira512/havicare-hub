<?php

namespace Hub\Location;

use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\reject;

final class ConcurrentLocationProvider implements LocationProviderContract
{
    private int $active = 0;

    /** @var list<array{request: array<string, mixed>, deferred: Deferred}> */
    private array $queue = [];

    public function __construct(
        private readonly LocationProviderContract $provider,
        private readonly int $maxConcurrent = 5,
        private readonly int $maxQueue = 1000,
    ) {
        if ($this->maxConcurrent < 1 || $this->maxQueue < 0) {
            throw new \InvalidArgumentException('Invalid location provider concurrency limits');
        }
    }

    public function name(): string
    {
        return $this->provider->name();
    }

    public function resolve(array $request): PromiseInterface
    {
        if ($this->active >= $this->maxConcurrent && count($this->queue) >= $this->maxQueue) {
            return reject(new LocationProviderException(
                "Location provider {$this->name()} queue is full",
                $this->name(),
            ));
        }

        $deferred = new Deferred();
        $this->queue[] = ['request' => $request, 'deferred' => $deferred];
        $this->drain();

        return $deferred->promise();
    }

    private function drain(): void
    {
        while ($this->active < $this->maxConcurrent && $this->queue !== []) {
            $entry = array_shift($this->queue);
            $this->active++;
            try {
                $promise = $this->provider->resolve($entry['request']);
            } catch (\Throwable $error) {
                $this->active--;
                $entry['deferred']->reject($error);
                continue;
            }

            $promise->then(
                function ($value) use ($entry): void {
                    $this->active--;
                    $entry['deferred']->resolve($value);
                    $this->drain();
                },
                function ($error) use ($entry): void {
                    $this->active--;
                    $entry['deferred']->reject($error);
                    $this->drain();
                },
            );
        }
    }
}
