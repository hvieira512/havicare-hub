<?php

declare(strict_types=1);

namespace Hub\Api\Services;

use Hub\Domain\DeviceMetadata;

/**
 * Quem é um aparelho, reunido das duas fontes que o sabem.
 *
 * @see DeviceDirectory::identify()
 */
final class DeviceIdentity
{
    /**
     * @param array<string, mixed> $device o instantâneo da dashboard, vazio se nunca falou
     * @param array<string, mixed>|null $modelRow a linha do catálogo de modelos
     */
    public function __construct(
        public readonly array $device,
        public readonly ?DeviceMetadata $metadata,
        public readonly string $supplier,
        public readonly string $model,
        public readonly string $protocol,
        public readonly ?array $modelRow,
    ) {
    }
}
