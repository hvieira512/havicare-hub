<?php

namespace App\Hub;

use Ratchet\ConnectionInterface;

class DeviceSession
{
    public function __construct(
        public readonly ConnectionInterface $connection,
        public readonly string $transport,
        public readonly bool $authenticated = false,
        public readonly string $imei = '',
        public readonly string $protocol = '',
        public readonly string $supplier = '',
        public readonly string $model = '',
    ) {
    }

    public function id(): string
    {
        return (string)$this->connection->resourceId;
    }

    public function identityContext(): array
    {
        return [
            'authenticated' => $this->authenticated,
            'imei' => $this->imei,
            'protocol' => $this->protocol,
            'supplier' => $this->supplier,
            'model' => $this->model,
            'transport' => $this->transport,
        ];
    }

    public function authenticate(DeviceIdentity $identity, string $supplier, string $model): self
    {
        return new self(
            connection: $this->connection,
            transport: $this->transport,
            authenticated: true,
            imei: $identity->imei,
            protocol: $identity->protocol,
            supplier: $supplier,
            model: $model,
        );
    }
}
