<?php

namespace Hub;

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
        public readonly string $deviceType = 'watch',
        public readonly string $licenseId = '0',
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
            'deviceType' => $this->deviceType,
            'licenseId' => $this->licenseId,
            'transport' => $this->transport,
        ];
    }

    public function authenticate(
        DeviceIdentity $identity,
        string $supplier,
        string $model,
        string $deviceType = 'watch',
        string $licenseId = '0'
    ): self
    {
        return new self(
            connection: $this->connection,
            transport: $this->transport,
            authenticated: true,
            imei: $identity->imei,
            protocol: $identity->protocol,
            supplier: $supplier,
            model: $model,
            deviceType: $deviceType,
            licenseId: $licenseId,
        );
    }
}
