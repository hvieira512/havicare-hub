<?php

declare(strict_types=1);

namespace Hub\Api\Http;

final class DevicePresentation
{
    public function __construct(private ModelImageUrl $images = new ModelImageUrl())
    {
    }

    /**
     * @param array<string, mixed> $device
     * @param array<string, mixed>|null $modelRow
     * @return array<string, mixed>
     */
    public function attachImage(array $device, ?array $modelRow, string $baseUrl): array
    {
        $device['image'] = $this->modelImage($modelRow, $baseUrl);

        return $device;
    }

    /** @param array<string, mixed>|null $modelRow */
    public function modelImage(?array $modelRow, string $baseUrl): ?string
    {
        if ($modelRow === null) {
            return null;
        }

        return $this->images->resolve((string)($modelRow['image_path'] ?? ''), $baseUrl);
    }
}
