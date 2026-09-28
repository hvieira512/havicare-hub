<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Veepoo;

/**
 * A pulseira e o gateway que a serve, resolvidos uma vez por mensagem.
 *
 * Tudo o que sai desta ingestão precisa dos mesmos cinco campos, e são eles que dizem por
 * conta de quem se publica.
 */
final readonly class BraceletContext
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
