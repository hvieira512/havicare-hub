<?php

namespace Hub\Watch;

final class WatchResponse
{
    public function __construct(
        public readonly string $bytes,
        public readonly bool $publishRaw = false,
    ) {
    }
}
