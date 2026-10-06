<?php

declare(strict_types=1);

namespace Hub\Location;

use React\Promise\PromiseInterface;

interface LocationProviderContract
{
    public function name(): string;

    /**
     * @param array<string, mixed> $request
     * @return PromiseInterface<array{httpStatus: int, body: array<string, mixed>, provider?: string}>
     */
    public function resolve(array $request): PromiseInterface;
}
