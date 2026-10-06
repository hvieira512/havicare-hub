<?php

declare(strict_types=1);

namespace Hub\Location;

use React\Promise\PromiseInterface;

interface LocationTelemetryEnricherContract
{
    /**
     * @param array<string, mixed> $telemetry
     * @return PromiseInterface<array<string, mixed>>
     */
    public function enrich(array $telemetry): PromiseInterface;
}
