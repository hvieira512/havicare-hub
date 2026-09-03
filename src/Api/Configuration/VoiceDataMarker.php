<?php

declare(strict_types=1);

namespace Hub\Api\Configuration;

/**
 * Troca o áudio de um payload de configuração pela marca de que ele existe.
 *
 * O aviso de medicação da 4P Touch traz a gravação em base64 dentro do `voiceData`, e um
 * ficheiro de 42 s são 978 KB numa linha. A marca -- `voiceDataAvailable` e `voiceDataBytes`
 * -- é o vocabulário que a API já falava para o mesmo efeito, e que o ecrã já sabe ler.
 *
 * O tecto decide quem passa: zero marca sempre, e é o que o histórico usa, porque quem lê uma
 * revisão antiga quer saber o que mudou e não ouvir o anexo. O modelo de leitura da API usa um
 * tecto de 64 KB, que serve o áudio pequeno tal e qual.
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
