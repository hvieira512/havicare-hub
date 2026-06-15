<?php

namespace Hub;

class DeviceIdentity
{
    public function __construct(
        public readonly string $imei,
        public readonly string $protocol,
        public readonly string $model = '',
        public readonly string $ident = '',
    ) {
    }
}
