<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\Configuration\Payload\FourPTouchPayloadBuilder;
use PHPUnit\Framework\TestCase;

/** O `ffmpeg` corre síncrono no event loop, e um lembrete do TAKEPILLS tem no máximo 15 segundos. */
final class FourPTouchVoiceDataLimitTest extends TestCase
{
    /** Um lembrete válido, para o payload não falhar por outra razão. */
    private const REMINDER = ['reminderSettings' => [['time' => '08:00', 'enabled' => true, 'frequency' => 1]], 'reminderText' => 'Tomar'];

    public function testRejectsVoiceDataAboveTheLimitBeforeDecodingIt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('voiceData');

        FourPTouchPayloadBuilder::build('takePills', self::REMINDER + [
            'voiceData' => str_repeat('A', 4_000_000),
        ]);
    }

    /** O tecto aplica-se ao áudio, e não à moldura: um `data:` à frente não o contorna. */
    public function testRejectsAnOversizedDataUriToo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('voiceData');

        FourPTouchPayloadBuilder::build('takePills', self::REMINDER + [
            'voiceData' => 'data:audio/webm;base64,' . str_repeat('A', 4_000_000),
        ]);
    }
}
