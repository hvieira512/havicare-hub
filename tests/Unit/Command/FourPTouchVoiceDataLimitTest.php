<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\Configuration\Payload\FourPTouchPayloadBuilder;
use PHPUnit\Framework\TestCase;

/**
 * O tecto do áudio de voz do TAKEPILLS.
 *
 * A conversão para AMR corre o `ffmpeg` num subprocesso síncrono, dentro do event loop que
 * também serve a ingestão dos relógios e a dashboard. O corpo de um pedido da API aceita 6 MB,
 * o que dava ~4,5 MB de bytes escolhidos por quem chama entregues ao conversor, com tudo o
 * resto parado enquanto ele trabalha.
 *
 * Um lembrete são no máximo 15 segundos de áudio -- o próprio comando do `ffmpeg` corta aí --,
 * portanto o tecto não tira nada a ninguém e fecha a amplificação.
 */
final class FourPTouchVoiceDataLimitTest extends TestCase
{
    /** Um lembrete válido, para o payload não falhar por outra razão. */
    private const REMINDER = ['reminderSettings' => '08:00-1-1', 'reminderText' => 'Tomar'];

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

    /**
     * O que não pode partir: um lembrete sem voz continua a produzir o comando, e sem chegar
     * ao `ffmpeg`. É o caminho que a dashboard usa na esmagadora maioria dos casos.
     */
    public function testAReminderWithoutVoiceStillBuilds(): void
    {
        $fields = FourPTouchPayloadBuilder::build('takePills', self::REMINDER)['fields'];

        self::assertSame('08:00-1-1', $fields[0]);
        self::assertSame('1', $fields[1]);
        self::assertSame('', $fields[3], 'o campo da voz fica vazio, e nenhum processo é lançado');
    }

    /** E um plano vazio continua a desligar o slot nativo, como antes. */
    public function testAnEmptyPlanStillDisablesTheNativeSlot(): void
    {
        $fields = FourPTouchPayloadBuilder::build('takePills', ['reminderSettings' => []])['fields'];

        self::assertSame('00:00-0-1', $fields[0]);
        self::assertSame('', $fields[3]);
    }
}
