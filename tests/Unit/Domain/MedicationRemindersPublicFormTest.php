<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use Hub\Domain\Capability\Medication\MedicationRemindersCapability;
use PHPUnit\Framework\TestCase;

/**
 * A mesma chave tem de querer dizer a mesma coisa nos dois aparelhos: um plano é um
 * medicamento com as suas horas, e quem integra lê-o sem saber de que fornecedor é.
 */
final class MedicationRemindersPublicFormTest extends TestCase
{
    /** Cada compartimento do M228 é um plano sem nome, com uma hora e o seu slot. */
    public function testTheDispenserGivesOnePlanPerSlot(): void
    {
        $value = (new MedicationRemindersCapability())->fromNative('zayata-m228', 'medication_reminders', [
            'plans' => [
                ['slot' => 1, 'hour' => 8, 'minute' => 0],
                ['slot' => 3, 'hour' => 20, 'minute' => 30],
            ],
        ]);

        self::assertSame([
            'plans' => [
                ['times' => [['time' => '08:00', 'enabled' => true, 'slot' => 1, 'recurrence' => ['kind' => 'daily']]]],
                ['times' => [['time' => '20:30', 'enabled' => true, 'slot' => 3, 'recurrence' => ['kind' => 'daily']]]],
            ],
        ], $value);
    }

    /** O 4P Touch não agrupa, e o texto e a voz são dele e ficam ao lado dos planos. */
    public function testTheFourPTouchGivesOnePlanPerReminder(): void
    {
        $value = (new MedicationRemindersCapability())->fromNative('four-p-touch', 'takePills', [
            'reminderSettings' => [
                ['time' => '0800', 'enabled' => true, 'frequency' => 2, 'custom' => ''],
                ['time' => '2030', 'enabled' => false, 'frequency' => 3, 'custom' => '1010100'],
            ],
            'reminderText' => 'Tomar',
            'voiceData' => '',
        ]);

        self::assertSame([
            ['times' => [['time' => '08:00', 'enabled' => true, 'recurrence' => ['kind' => 'daily']]]],
            ['times' => [[
                'time' => '20:30',
                'enabled' => false,
                // A máscara do 4P Touch começa ao domingo: a posição 0 é o dia 7.
                'recurrence' => ['kind' => 'custom', 'days' => [7, 2, 4]],
            ]]],
        ], $value['plans']);
        self::assertSame('Tomar', $value['reminderText']);
    }

    /** O slot do dispensador sobrevive à ida ao fio, e o `enabled` também: é o que limpa o compartimento. */
    public function testTheDispenserSlotSurvivesTheRoundTrip(): void
    {
        $capability = new MedicationRemindersCapability();
        $public = ['plans' => [
            ['times' => [['time' => '08:00', 'enabled' => true, 'slot' => 1, 'recurrence' => ['kind' => 'daily']]]],
            ['times' => [['time' => '20:30', 'enabled' => false, 'slot' => 3, 'recurrence' => ['kind' => 'daily']]]],
        ]];

        $native = $capability->toNative('zayata-m228', $public)['medication_reminders'];

        self::assertSame(
            [
                ['slot' => 1, 'hour' => 8, 'minute' => 0, 'enabled' => true],
                ['slot' => 3, 'hour' => 20, 'minute' => 30, 'enabled' => false],
            ],
            $native['plans'],
            'o nativo do M228',
        );
        self::assertSame($public, $capability->fromNative('zayata-m228', 'medication_reminders', $native));
    }

    /** Vazio são os nove alarmes desligados, o ponto de partida de um dispensador por configurar. */
    public function testAnEmptyDispenserPlanIsAValidPlan(): void
    {
        self::assertSame(
            ['plans' => []],
            (new MedicationRemindersCapability())->toNative('zayata-m228', ['plans' => []])['medication_reminders'],
        );
    }

    /** A forma pública do 4P Touch volta ao formato nativo que ele partilha com os alarmes. */
    public function testTheFourPTouchPublicFormTranslatesToItsNativeForm(): void
    {
        $capability = new MedicationRemindersCapability();
        $public = [
            'plans' => [
                ['times' => [['time' => '11:25', 'enabled' => true, 'recurrence' => ['kind' => 'daily']]]],
                ['times' => [[
                    'time' => '18:00',
                    'enabled' => false,
                    'recurrence' => ['kind' => 'custom', 'days' => [1, 3, 5]],
                ]]],
            ],
            'reminderText' => 'meds',
            'voiceData' => '',
        ];

        $native = $capability->toNative('four-p-touch', $public)['takePills'];

        self::assertSame([
            ['time' => '11:25', 'enabled' => true, 'frequency' => 2, 'custom' => ''],
            ['time' => '18:00', 'enabled' => false, 'frequency' => 3, 'custom' => '0101010'],
        ], $native['reminderSettings']);
        self::assertSame(2, $native['number'], 'o `number` é derivado e nativo');
        self::assertSame($public, $capability->fromNative('four-p-touch', 'takePills', $native));
    }

    /** O que um fornecedor não sabe omite-se, como em todo o contrato. */
    public function testWhatASupplierDoesNotKnowIsOmitted(): void
    {
        $capability = new MedicationRemindersCapability();

        foreach (['zayata-m228' => 'medication_reminders', 'four-p-touch' => 'takePills'] as $protocol => $nativeKey) {
            $desired = $protocol === 'zayata-m228'
                ? ['plans' => [['slot' => 1, 'hour' => 8, 'minute' => 0]]]
                : ['reminderSettings' => [['time' => '0800', 'enabled' => true, 'frequency' => 2, 'custom' => '']]];

            $plan = $capability->fromNative($protocol, $nativeKey, $desired)['plans'][0];

            self::assertArrayNotHasKey('name', $plan, $protocol);
            self::assertArrayNotHasKey('doseCount', $plan, $protocol);
            self::assertArrayNotHasKey('startDate', $plan, $protocol);
        }
    }
}
