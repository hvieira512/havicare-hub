<?php

namespace App\Domain\Normalizer;

final class ProtocolEventNormalizerRegistry
{
    /** @var ProtocolEventNormalizerInterface[]|null */
    private static ?array $normalizers = null;

    public static function apply(
        ?string $feature,
        ?string $nativeType,
        array $payload,
        array $normalized,
        ?string $protocol = null,
    ): array {
        $current = $normalized;

        foreach (self::normalizers() as $normalizer) {
            if (!$normalizer->canNormalize($feature, $nativeType, $payload, $protocol)) {
                continue;
            }

            $current = $normalizer->normalize($feature, $nativeType, $payload, $current);
        }

        return $current;
    }

    /**
     * @return ProtocolEventNormalizerInterface[]
     */
    private static function normalizers(): array
    {
        if (self::$normalizers !== null) {
            return self::$normalizers;
        }

        self::$normalizers = [
            new VivistarEventNormalizer(),
        ];

        return self::$normalizers;
    }
}
