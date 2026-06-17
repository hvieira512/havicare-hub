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

    public function withImei(string $imei): self
    {
        return new self(
            imei: $imei,
            protocol: $this->protocol,
            model: $this->model,
            ident: $this->ident,
        );
    }
}
