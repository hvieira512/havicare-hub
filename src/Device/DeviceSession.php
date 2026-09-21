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
        // Sem valor por omissão que seja um tipo real: uma sessão por autenticar não sabe o
        // que está do outro lado, e adivinhar «watch» punha um dispensador de comprimidos a
        // publicar no tópico dos relógios sem dar erro nenhum. O tipo chega no `authenticate`,
        // vindo da whitelist.
        public readonly string $deviceType = '',
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
        string $deviceType = '',
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
