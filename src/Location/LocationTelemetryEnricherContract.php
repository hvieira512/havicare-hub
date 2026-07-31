<?php

namespace Hub\Location;

use React\Promise\PromiseInterface;

interface LocationTelemetryEnricherContract
{
    /** @return PromiseInterface<array<string, mixed>> */
    public function enrich(array $telemetry): PromiseInterface;
}
