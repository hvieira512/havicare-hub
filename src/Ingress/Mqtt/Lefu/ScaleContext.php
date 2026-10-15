<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Lefu;

/** A balança e o gateway que a serve, resolvidos uma vez por mensagem. */
final readonly class ScaleContext
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
