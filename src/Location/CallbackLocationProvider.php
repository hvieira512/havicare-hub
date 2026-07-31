<?php

namespace Hub\Location;

use React\Promise\PromiseInterface;

final class CallbackLocationProvider implements LocationProviderContract
{
    private \Closure $resolver;

    public function __construct(callable $resolver, private readonly string $providerName = 'callback')
    {
        $this->resolver = \Closure::fromCallable($resolver);
    }

    public function name(): string
    {
        return $this->providerName;
    }

    public function resolve(array $request): PromiseInterface
    {
        $promise = ($this->resolver)($request);
        if (!$promise instanceof PromiseInterface) {
            throw new \RuntimeException('Location provider callback must return a promise');
        }

        return $promise;
    }
}
