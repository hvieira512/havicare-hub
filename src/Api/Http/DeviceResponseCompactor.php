<?php

namespace Hub\Api\Http;

use Hub\Api\Configuration\VoiceDataMarker;

final class DeviceResponseCompactor
{
    private const MAX_INLINE_VOICE_DATA_BYTES = 65536;

    private VoiceDataMarker $marker;

    public function __construct()
    {
        $this->marker = new VoiceDataMarker(self::MAX_INLINE_VOICE_DATA_BYTES);
    }

    /**
     * Mantém os valores binários grandes de configuração fora do modelo de leitura do
     * detalhe de um dispositivo.
     *
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    public function compact(array $response): array
    {
        return $this->marker->mark($response);
    }
}
