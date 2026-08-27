<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

/**
 * Um WAV de silêncio, que é o que os testes do lembrete de medicação precisam de dar ao
 * transcodificador.
 *
 * O 4P Touch quer AMR-NB, e o `FourPTouchPayloadBuilder` chega lá por ffmpeg a partir de PCM
 * de 8 kHz mono 16 bits -- daí os valores por omissão. O conteúdo não importa; o cabeçalho
 * importa, porque é o que o ffmpeg lê para saber o que lhe deram.
 */
final class WavFixture
{
    public static function silenceBase64(
        int $samples = 800,
        int $sampleRate = 8000,
        int $channels = 1,
        int $bitsPerSample = 16,
    ): string {
        $data = str_repeat(pack('v', 0), $samples);
        $byteRate = (int)($sampleRate * $channels * ($bitsPerSample / 8));
        $blockAlign = (int)($channels * ($bitsPerSample / 8));

        $header = 'RIFF'
            . pack('V', 36 + strlen($data))
            . 'WAVE'
            . 'fmt '
            . pack('V', 16)
            . pack('v', 1)
            . pack('v', $channels)
            . pack('V', $sampleRate)
            . pack('V', $byteRate)
            . pack('v', $blockAlign)
            . pack('v', $bitsPerSample)
            . 'data'
            . pack('V', strlen($data));

        return base64_encode($header . $data);
    }
}
