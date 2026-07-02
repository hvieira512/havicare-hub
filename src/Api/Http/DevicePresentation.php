<?php

namespace Hub\Api\Http;

final class DevicePresentation
{
    public function __construct(private ModelImageUrl $images = new ModelImageUrl())
    {
    }

    public function attachImage(array $device, ?array $modelRow, string $baseUrl): array
    {
        $device['image'] = $this->modelImage($modelRow, $baseUrl);

        return $device;
    }

    public function modelImage(?array $modelRow, string $baseUrl): ?string
    {
        if ($modelRow === null) {
            return null;
        }

        return $this->images->resolve((string)($modelRow['image_path'] ?? ''), $baseUrl);
    }
}
