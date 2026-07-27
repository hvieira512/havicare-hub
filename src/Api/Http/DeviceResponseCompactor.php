<?php

namespace Hub\Api\Http;

final class DeviceResponseCompactor
{
    private const MAX_INLINE_VOICE_DATA_BYTES = 65536;

    /**
     * Keep large binary configuration values out of the device detail read model.
     *
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    public function compact(array $response): array
    {
        return $this->compactValue($response);
    }

    private function compactValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (isset($value['voiceData']) && is_string($value['voiceData'])) {
            $voiceData = trim($value['voiceData']);
            if (strlen($voiceData) > self::MAX_INLINE_VOICE_DATA_BYTES) {
                unset($value['voiceData']);
                $value['voiceDataAvailable'] = true;
                $value['voiceDataBytes'] = $this->decodedBytes($voiceData);
            }
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->compactValue($item);
        }

        return $value;
    }

    private function decodedBytes(string $voiceData): int
    {
        if (str_starts_with($voiceData, 'data:')) {
            $separator = strpos($voiceData, ',');
            if ($separator !== false) {
                $voiceData = substr($voiceData, $separator + 1);
            }
        }

        $decoded = base64_decode($voiceData, true);

        return is_string($decoded) ? strlen($decoded) : 0;
    }
}
