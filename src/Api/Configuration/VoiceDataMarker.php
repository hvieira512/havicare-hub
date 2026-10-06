<?php

declare(strict_types=1);

namespace Hub\Api\Configuration;

/**
 * Troca o áudio em base64 do `voiceData` pela marca `voiceDataAvailable`/`voiceDataBytes`. O tecto
 * decide: zero marca sempre, que é o do histórico; a API usa 64 KB e serve o áudio pequeno.
 */
final class VoiceDataMarker
{
    public function __construct(private int $keepInlineUpToBytes = 0)
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function mark(array $payload): array
    {
        /** @var array<string, mixed> $marked */
        $marked = $this->markValue($payload);

        return $marked;
    }

    private function markValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (isset($value['voiceData']) && is_string($value['voiceData'])) {
            $voiceData = trim($value['voiceData']);
            // A voz desligada chega como cadeia vazia, e uma ausência não se marca.
            if ($voiceData !== '' && strlen($voiceData) > $this->keepInlineUpToBytes) {
                unset($value['voiceData']);
                $value['voiceDataAvailable'] = true;
                $value['voiceDataBytes'] = $this->decodedBytes($voiceData);
            }
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->markValue($item);
        }

        return $value;
    }

    /** Aceita base64 puro e o data URI inteiro, que é como as linhas antigas o guardaram. */
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
