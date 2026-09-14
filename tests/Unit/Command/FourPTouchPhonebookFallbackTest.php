<?php

namespace Tests\Unit\Command;

use Hub\Command\Configuration\Payload\FourPTouchPhonebookFallback;
use PHPUnit\Framework\TestCase;

final class FourPTouchPhonebookFallbackTest extends TestCase
{
    public function testTheFirstFiveContactsGoInASinglePhb(): void
    {
        $commands = FourPTouchPhonebookFallback::commands([
            ['name' => 'Hugo', 'phone' => '+351938854803'],
            ['name' => 'Ricardo', 'phone' => '+351965401976'],
        ]);

        self::assertSame(
            [[
                'command' => 'PHB',
                'fields' => [
                    '+351938854803', '004800750067006F',
                    '+351965401976', '005200690063006100720064006F',
                ],
            ]],
            $commands,
            'o PHB antigo leva a lista inteira numa trama, em pares número/nome'
        );
    }

    public function testTheSixthContactOnwardsGoesInPhb2(): void
    {
        $contacts = [];
        for ($i = 1; $i <= 7; $i++) {
            $contacts[] = ['name' => "C{$i}", 'phone' => "91000000{$i}"];
        }

        $commands = FourPTouchPhonebookFallback::commands($contacts);

        self::assertSame(['PHB', 'PHB2'], array_column($commands, 'command'));
        self::assertCount(10, $commands[0]['fields']);
        self::assertCount(4, $commands[1]['fields']);
    }

    public function testNamesAreCutToWhatTheOldCommandHolds(): void
    {
        $commands = FourPTouchPhonebookFallback::commands([
            ['name' => 'AbcdefghijKLM', 'phone' => '910000001'],
        ]);

        self::assertSame(
            strtoupper(bin2hex(iconv('UTF-8', 'UTF-16BE', 'Abcdefghij'))),
            $commands[0]['fields'][1],
            'o PHB só guarda dez caracteres, e mandar mais arriscava a trama inteira'
        );
    }

    public function testContactsBeyondTheTenthAreDropped(): void
    {
        $contacts = [];
        for ($i = 1; $i <= 12; $i++) {
            $contacts[] = ['name' => "C{$i}", 'phone' => '91000' . str_pad((string)$i, 4, '0', STR_PAD_LEFT)];
        }

        $commands = FourPTouchPhonebookFallback::commands($contacts);

        self::assertCount(2, $commands);
        self::assertCount(10, $commands[1]['fields'], 'o PHB2 fecha na décima posição');
    }

    public function testFallingBackNeedsEverySendToHaveFailed(): void
    {
        $expirado = ['nativeType' => 'PHBX2', 'status' => 'failed', 'error' => 'response_timeout'];
        $confirmado = ['nativeType' => 'PHBX2', 'status' => 'acked'];

        self::assertTrue(
            FourPTouchPhonebookFallback::shouldFallBack([$expirado, $expirado]),
            'nenhum confirmado é o que distingue um firmware que não fala o comando'
        );
        self::assertFalse(
            FourPTouchPhonebookFallback::shouldFallBack([$confirmado, $expirado]),
            'um confirmado prova que o aparelho entende o comando, e o outro é só um envio perdido'
        );
        self::assertFalse(
            FourPTouchPhonebookFallback::shouldFallBack([$expirado, ['nativeType' => 'PHBX2', 'status' => 'waiting']]),
            'enquanto houver um por responder ainda não se sabe nada'
        );
        self::assertFalse(FourPTouchPhonebookFallback::shouldFallBack([]));
    }

    public function testTheListIsRebuiltFromTheCommandsThatFailed(): void
    {
        $contacts = FourPTouchPhonebookFallback::contactsFromCommands([
            ['nativeType' => 'PHBX2', 'payload' => ['fields' => ['2', '005200690063006100720064006F', '+351965401976']]],
            ['nativeType' => 'DPHBX', 'payload' => ['fields' => ['7']]],
            ['nativeType' => 'PHBX2', 'payload' => ['fields' => ['1', '004800750067006F', '+351938854803']]],
        ]);

        self::assertSame(
            [
                ['name' => 'Hugo', 'phone' => '+351938854803'],
                ['name' => 'Ricardo', 'phone' => '+351965401976'],
            ],
            $contacts,
            'pela ordem do índice, e as remoções não entram na lista antiga'
        );
    }

    public function testAnEmptyListStillClearsTheDevice(): void
    {
        self::assertSame(
            [['command' => 'PHB', 'fields' => []]],
            FourPTouchPhonebookFallback::commands([]),
            'um PHB sem campos é o que limpa a lista no aparelho'
        );
    }
}
