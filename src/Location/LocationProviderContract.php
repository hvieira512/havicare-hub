<?php

namespace Hub\Location;

use React\Promise\PromiseInterface;

interface LocationProviderContract
{
    public function name(): string;

    /** @return PromiseInterface<array{httpStatus: int, body: array<string, mixed>, provider?: string}> */
    public function resolve(array $request): PromiseInterface;
}
