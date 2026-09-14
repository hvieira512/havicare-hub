<?php

namespace Tests\Unit\Command;

use Hub\Command\Configuration\Payload\FourPTouchPhonebookDelta;
use PHPUnit\Framework\TestCase;

final class FourPTouchPhonebookDeltaTest extends TestCase
{
    private const HUGO = ['name' => 'Hugo', 'phone' => '+351938854803'];
    private const RICARDO = ['name' => 'Ricardo', 'phone' => '+351965401976'];
    private const ANA = ['name' => 'Ana', 'phone' => '+351911111111'];

    public function testRemovingOneContactEmitsASingleDelete(): void
    {
        $commands = FourPTouchPhonebookDelta::commands(
            [1 => self::HUGO, 2 => self::RICARDO, 3 => self::ANA],
            [self::HUGO, self::RICARDO],
        );

        self::assertSame(
            [['command' => 'DPHBX', 'fields' => ['3']]],
            $commands,
            'remover um contacto escreve só a remoção, e não a lista inteira'
        );
    }

    public function testRenamingKeepsTheIndex(): void
    {
        $commands = FourPTouchPhonebookDelta::commands(
            [1 => self::HUGO, 2 => self::RICARDO],
            [['name' => 'HugoV', 'phone' => '+351938854803'], self::RICARDO],
        );

        self::assertSame(
            [['command' => 'PHBX2', 'fields' => ['1', '004800750067006F0056', '+351938854803']]],
            $commands,
            'o telefone é a identidade: mudar o nome sobrepõe o mesmo índice'
        );
    }

    public function testChangingThePhoneIsADifferentContact(): void
    {
        $commands = FourPTouchPhonebookDelta::commands(
            [1 => self::HUGO],
            [['name' => 'Hugo', 'phone' => '+351900000000']],
        );

        self::assertSame(
            [
                ['command' => 'DPHBX', 'fields' => ['1']],
                ['command' => 'PHBX2', 'fields' => ['1', '004800750067006F', '+351900000000']],
            ],
            $commands,
            'a remoção sai primeiro, para o índice poder ser reocupado na mesma passagem'
        );
    }

    public function testAnUntouchedListEmitsNothing(): void
    {
        $commands = FourPTouchPhonebookDelta::commands(
            [1 => self::HUGO, 2 => self::RICARDO],
            [self::HUGO, self::RICARDO],
        );

        self::assertSame([], $commands);
    }

    public function testANewContactTakesTheLowestFreeIndex(): void
    {
        $commands = FourPTouchPhonebookDelta::commands(
            [2 => self::RICARDO, 3 => self::ANA],
            [self::RICARDO, self::ANA, self::HUGO],
        );

        self::assertSame(
            [['command' => 'PHBX2', 'fields' => ['1', '004800750067006F', '+351938854803']]],
            $commands,
            'o índice 1 ficou livre de uma remoção anterior e é reaproveitado'
        );
    }

    public function testTheOrderOnTheWireIgnoresTheOrderOfTheList(): void
    {
        $commands = FourPTouchPhonebookDelta::commands(
            [1 => self::HUGO, 2 => self::RICARDO],
            [
                ['name' => 'RicardoB', 'phone' => '+351965401976'],
                ['name' => 'HugoV', 'phone' => '+351938854803'],
            ],
        );

        self::assertSame(['1', '2'], array_column(array_column($commands, 'fields'), 0));
    }

    public function testTheResultingAssignmentKeepsIndicesAndFillsHoles(): void
    {
        $indexed = FourPTouchPhonebookDelta::indexed(
            [2 => self::RICARDO, 5 => self::ANA],
            [self::ANA, self::HUGO, self::RICARDO],
        );

        self::assertSame(
            [1 => self::HUGO, 2 => self::RICARDO, 5 => self::ANA],
            $indexed,
            'quem já tinha índice mantém-no, e o novo ocupa o primeiro buraco'
        );
    }

    public function testStateWrittenBeforeIndicesExistedIsTreatedAsUnknown(): void
    {
        // Uma linha guardada pelo PHB é uma lista simples, e as suas chaves 0 e 1 não são
        // índices do aparelho. Tomá-las por índices escrevia no índice 0, fora da gama.
        $commands = FourPTouchPhonebookDelta::commands(
            [self::HUGO, self::RICARDO],
            [self::HUGO, self::RICARDO],
        );

        self::assertSame(
            [
                ['command' => 'PHBX2', 'fields' => ['1', '004800750067006F', '+351938854803']],
                ['command' => 'PHBX2', 'fields' => ['2', '005200690063006100720064006F', '+351965401976']],
            ],
            $commands,
            'sem índices de confiança, reescreve-se a lista inteira a partir do índice 1'
        );
    }

    public function testStateFromAFailedDeliveryIsNotTrusted(): void
    {
        $guardado = [1 => self::HUGO, 2 => self::RICARDO];

        self::assertSame(
            $guardado,
            FourPTouchPhonebookDelta::trustedPrevious($guardado, 'confirmed'),
            'uma entrega confirmada é a base do delta seguinte'
        );

        foreach (['failed', 'retry_exhausted', 'response_timeout', 'created'] as $estado) {
            self::assertSame(
                [],
                FourPTouchPhonebookDelta::trustedPrevious($guardado, $estado),
                "o estado guardado não descreve o aparelho depois de {$estado}"
            );
        }
    }

    public function testAfterAFailedDeliveryTheWholeListIsWrittenAgain(): void
    {
        $guardado = [1 => self::HUGO, 2 => self::RICARDO];

        $commands = FourPTouchPhonebookDelta::commands(
            FourPTouchPhonebookDelta::trustedPrevious($guardado, 'failed'),
            [self::HUGO, self::RICARDO],
        );

        self::assertSame(
            ['PHBX2', 'PHBX2'],
            array_column($commands, 'command'),
            'reenviar a mesma lista depois de uma falha tem de voltar a escrever, e não dar zero comandos'
        );
    }

    public function testAResyncSweepsEveryIndexItIsNotAboutToWrite(): void
    {
        $commands = FourPTouchPhonebookDelta::resyncCommands([self::HUGO, self::RICARDO]);

        $removidos = array_values(array_filter(
            $commands,
            static fn(array $c): bool => $c['command'] === 'DPHBX'
        ));
        $escritos = array_values(array_filter(
            $commands,
            static fn(array $c): bool => $c['command'] === 'PHBX2'
        ));

        self::assertCount(FourPTouchPhonebookDelta::MAX_CONTACTS - 2, $removidos);
        self::assertSame('3', $removidos[0]['fields'][0], 'os índices 1 e 2 vão ser escritos, e não se apagam');
        self::assertSame('100', $removidos[count($removidos) - 1]['fields'][0]);
        self::assertSame(
            [['1', '004800750067006F', '+351938854803'], ['2', '005200690063006100720064006F', '+351965401976']],
            array_column($escritos, 'fields'),
            'a lista desejada é reescrita de raiz, a partir do índice 1'
        );
        self::assertSame('DPHBX', $commands[0]['command'], 'as remoções saem antes das escritas');
    }

    public function testAResyncOfAnEmptyListClearsTheDeviceWhole(): void
    {
        $commands = FourPTouchPhonebookDelta::resyncCommands([]);

        self::assertCount(FourPTouchPhonebookDelta::MAX_CONTACTS, $commands);
        self::assertSame(['DPHBX'], array_unique(array_column($commands, 'command')));
    }

    public function testMoreContactsThanTheDeviceHoldsIsRejected(): void
    {
        $desired = [];
        for ($i = 1; $i <= FourPTouchPhonebookDelta::MAX_CONTACTS + 1; $i++) {
            $desired[] = ['name' => "C{$i}", 'phone' => '+35190000' . str_pad((string)$i, 4, '0', STR_PAD_LEFT)];
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('contacts must not exceed 100 items');

        FourPTouchPhonebookDelta::commands([], $desired);
    }
}
