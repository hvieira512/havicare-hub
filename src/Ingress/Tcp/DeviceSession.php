<?php

declare(strict_types=1);

namespace Hub\Ingress\Tcp;

class DeviceSession
{
    // Uma ligação que nunca autentica manda tramas para sempre: o aviso sai uma vez só.
    public bool $unidentifiedWarningLogged = false;

    public function __construct(
        public readonly ConnectionInterface $connection,
        public readonly bool $authenticated = false,
        public readonly string $imei = '',
        public readonly string $protocol = '',
        public readonly string $supplier = '',
        public readonly string $model = '',
        public readonly string $commercialName = '',
        // O tipo chega no `authenticate`, vindo da whitelist.
        public readonly string $deviceType = '',
        public readonly int $licenseId = 0,
        public readonly string $company = 'null',
    ) {
    }

    /** @return array<string, mixed> */
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
        ];
    }

    public function authenticate(
        DeviceIdentity $identity,
        string $supplier,
        string $model,
        string $commercialName = '',
        string $deviceType = '',
        int $licenseId = 0,
        string $company = 'null',
    ): self {
        return new self(
            connection: $this->connection,
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
