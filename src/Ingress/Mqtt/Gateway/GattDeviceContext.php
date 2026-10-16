<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Gateway;

/** O aparelho e o gateway que o serve, resolvidos uma vez por mensagem. */
final readonly class GattDeviceContext
{
    /** @param array<string, mixed> $device */
    public function __construct(
        public string $deviceKey,
        public string $gatewayKey,
        public array $device,
        public int $licenseId,
        public string $company,
    ) {
    }
}
