<?php

declare(strict_types=1);

namespace Hub\Device;

/**
 * O descritor do dispositivo, na forma que todo o contrato usa. O que não se sabe omite-se:
 * uma chave vazia e uma ausente querem dizer o mesmo.
 */
final class DeviceDescriptor
{
    /**
     * @param array<string, mixed> $device o dispositivo como a whitelist o resolve
     *
     * @return array<string, string>
     */
    public static function of(string $deviceKey, array $device): array
    {
        return self::fromParts(
            $deviceKey,
            (string)($device['supplier'] ?? ''),
            (string)($device['model'] ?? ''),
            (string)($device['commercialName'] ?? ''),
        );
    }

    /** @return array<string, string> */
    public static function fromParts(
        string $deviceKey,
        string $supplier,
        string $model,
        string $commercialName = '',
    ): array {
        return array_filter([
            'id' => $deviceKey,
            'supplier' => $supplier,
            'model' => $model,
            'commercialName' => $commercialName,
        ], static fn(string $value): bool => $value !== '');
    }
}
