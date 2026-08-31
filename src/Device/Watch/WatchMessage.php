<?php

namespace Hub\Device\Watch;

final class WatchMessage
{
    /**
     * @param array<string, mixed> $decoded
     * @param array<int, array<string, mixed>> $telemetry
     * @param array<int, WatchResponse> $responses
     */
    public function __construct(
        public readonly array $decoded,
        public readonly array $telemetry = [],
        public readonly array $responses = [],
    ) {
    }
}
