<?php

declare(strict_types=1);

namespace Tests\Unit\Api\Configuration;

use Hub\Api\Configuration\VoiceDataMarker;
use PHPUnit\Framework\TestCase;

final class VoiceDataMarkerTest extends TestCase
{
    /** Um MP3 mínimo: tag ID3v2.3 vazia seguida de um frame MPEG-1 Layer III. */
    private function audio(): string
    {
        return "ID3\x03\x00\x00\x00\x00\x00\x00" . "\xFF\xFB\x90\x64" . str_repeat("\x00", 100);
    }

    public function testReplacesTheAudioWithTheMarkerTheApiAlreadySpeaks(): void
    {
        $audio = $this->audio();
        $payload = [
            'reminderText' => 'Tomar o comprimido',
            'voiceData' => base64_encode($audio),
            'voiceMimeType' => 'audio/mpeg',
        ];

        $marked = (new VoiceDataMarker())->mark($payload);

        self::assertArrayNotHasKey('voiceData', $marked);
        self::assertTrue($marked['voiceDataAvailable']);
        self::assertSame(strlen($audio), $marked['voiceDataBytes']);
        // O texto e o tipo ficam: são o que torna a marca legível sem o anexo.
        self::assertSame('Tomar o comprimido', $marked['reminderText']);
        self::assertSame('audio/mpeg', $marked['voiceMimeType']);
    }

    public function testCountsTheBytesOfADataUriToo(): void
    {
        $audio = $this->audio();
        $payload = ['voiceData' => 'data:audio/mpeg;base64,' . base64_encode($audio)];

        $marked = (new VoiceDataMarker())->mark($payload);

        self::assertArrayNotHasKey('voiceData', $marked);
        self::assertSame(strlen($audio), $marked['voiceDataBytes']);
    }

    public function testMarksAudioNestedInsideThePayload(): void
    {
        $payload = ['reminders' => [['time' => '09:00', 'voiceData' => base64_encode($this->audio())]]];

        $marked = (new VoiceDataMarker())->mark($payload);

        self::assertArrayNotHasKey('voiceData', $marked['reminders'][0]);
        self::assertTrue($marked['reminders'][0]['voiceDataAvailable']);
        self::assertSame('09:00', $marked['reminders'][0]['time']);
    }

    public function testLeavesAPayloadWithoutAudioExactlyAsItWas(): void
    {
        $payload = ['enabled' => true, 'time' => '09:00', 'frequency' => 2];

        self::assertSame($payload, (new VoiceDataMarker())->mark($payload));
    }

    public function testAnEmptyVoiceDataIsAnAbsenceAndNotAMarker(): void
    {
        // O ecrã envia `voiceData: ""` quando a voz está desligada. Marcar isso como
        // disponível fazia a dashboard oferecer um leitor para nada.
        $marked = (new VoiceDataMarker())->mark(['voiceData' => '', 'voiceMimeType' => '']);

        self::assertArrayNotHasKey('voiceDataAvailable', $marked);
        self::assertSame('', $marked['voiceData']);
    }

    public function testKeepsTheAudioWhenItFitsTheInlineBudget(): void
    {
        // O modelo de leitura da API serve o áudio pequeno tal e qual; é a mesma marca com
        // um tecto diferente.
        $payload = ['voiceData' => base64_encode($this->audio())];

        $marked = (new VoiceDataMarker(65536))->mark($payload);

        self::assertSame($payload, $marked);
    }
}
