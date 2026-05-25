<?php

namespace App\Domain\Normalizer;

interface ProtocolEventNormalizerInterface
{
    public function protocol(): string;

    public function canNormalize(?string $feature, ?string $nativeType, array $payload, ?string $protocol): bool;

    public function normalize(?string $feature, ?string $nativeType, array $payload, array $normalized): array;
}
