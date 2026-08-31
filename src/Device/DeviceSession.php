<?php

namespace Hub\Device;

class DeviceSession
{
    // Uma ligação que nunca autentica manda tramas para sempre: o aviso sai uma vez só.
    public bool $unidentifiedWarningLogged = false;

    public function __construct(
        public readonly ConnectionInterface $connection,
        public readonly string $transport,
        public readonly bool $authenticated = false,
        public readonly string $imei = '',
        public readonly string $protocol = '',
        public readonly string $supplier = '',
        public readonly string $model = '',
        public readonly string $commercialName = '',
        public readonly string $deviceType = 'watch',
        public readonly int $licenseId = 0,
        public readonly string $company = 'null',
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
            'commercialName' => $this->commercialName,
            'deviceType' => $this->deviceType,
            'licenseId' => $this->licenseId,
            'transport' => $this->transport,
        ];
    }

    public function authenticate(
        DeviceIdentity $identity,
        string $supplier,
        string $model,
        string $commercialName = '',
        string $deviceType = 'watch',
        int $licenseId = 0,
        string $company = 'null',
    ): self {
        return new self(
            connection: $this->connection,
            transport: $this->transport,
            authenticated: true,
            imei: $identity->imei,
            protocol: $identity->protocol,
            supplier: $supplier,
            model: $model,
            commercialName: $commercialName,
            deviceType: $deviceType,
            licenseId: $licenseId,
            company: $company,
        );
    }
}
