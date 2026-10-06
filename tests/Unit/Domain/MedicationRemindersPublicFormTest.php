<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use Hub\Domain\Capability\Medication\MedicationRemindersCapability;
use PHPUnit\Framework\TestCase;

/**
 * A mesma chave tem de querer dizer a mesma coisa nos três aparelhos: um plano é um
 * medicamento com as suas horas, e quem integra lê-o sem saber de que fornecedor é.
 */
final class MedicationRemindersPublicFormTest extends TestCase
{
    public function testWonlexGroupsItsPeriodsUnderOneMedication(): void
    {
        $value = (new MedicationRemindersCapability())->fromNative('wonlex-json', 'dnMedicationPlan', [
            'plans' => [[
                'drugType' => 0,
                'drugName' => 'Paracetamol',
                'drugDose' => 2,
                'drugUnit' => '0',
                'drugStartTime' => '2026-10-01',
                'drugEndTime' => '2026-10-10',
                'drugInterval' => 1,
                'drugTime' => [
                    'alarmClock' => ['Morning' => '08:00', 'Night' => '19:00'],
                    'checkboxes' => [0, 2],
                    'radio' => 0,
                ],
            ]],
        ]);

        self::assertSame([
            'plans' => [[
                'name' => 'Paracetamol',
                'condition' => 'hypertension',
                'doseCount' => 2.0,
                'doseUnit' => 'tablet',
                'startDate' => '2026-10-01',
                'endDate' => '2026-10-10',
                'intervalDays' => 1.0,
                'mealTiming' => 'before_meal',
                'times' => [
                    ['time' => '08:00', 'enabled' => true, 'period' => 'morning', 'recurrence' => ['kind' => 'daily']],
                    ['time' => '19:00', 'enabled' => true, 'period' => 'night', 'recurrence' => ['kind' => 'daily']],
                ],
            ]],
        ], $value);
    }

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

    /** A forma pública traduz-se mesmo para o nativo da Wonlex, e volta igual. */
    public function testTheWonlexPublicFormTranslatesToItsNativeForm(): void
    {
        $capability = new MedicationRemindersCapability();
        $public = ['plans' => [[
            'name' => 'Paracetamol',
            'condition' => 'diabetes',
            'doseCount' => 2.0,
            'doseUnit' => 'mg',
            'startDate' => '2026-10-01',
            'endDate' => '2026-10-10',
            'intervalDays' => 1.0,
            'mealTiming' => 'after_meal',
            'times' => [
                ['time' => '08:00', 'enabled' => true, 'period' => 'morning', 'recurrence' => ['kind' => 'daily']],
                ['time' => '19:00', 'enabled' => true, 'period' => 'night', 'recurrence' => ['kind' => 'daily']],
            ],
        ]]];

        $native = $capability->toNative('wonlex-json', $public)['dnMedicationPlan'];

        self::assertSame([
            'drugType' => 1,
            'drugName' => 'Paracetamol',
            'drugDose' => 2.0,
            'drugUnit' => '3',
            'drugStartTime' => '2026-10-01',
            'drugEndTime' => '2026-10-10',
            'drugInterval' => 1.0,
            'drugTime' => [
                'alarmClock' => ['Morning' => '08:00', 'Night' => '19:00'],
                'checkboxes' => [0, 2],
                'radio' => 1,
            ],
        ], $native['plans'][0], 'o nativo da Wonlex');

        self::assertSame($public, $capability->fromNative('wonlex-json', 'dnMedicationPlan', $native));
    }

    /** O slot do dispensador sobrevive à ida ao fio. */
    public function testTheDispenserSlotSurvivesTheRoundTrip(): void
    {
        $capability = new MedicationRemindersCapability();
        $public = ['plans' => [
            ['times' => [['time' => '08:00', 'enabled' => true, 'slot' => 1, 'recurrence' => ['kind' => 'daily']]]],
            ['times' => [['time' => '20:30', 'enabled' => true, 'slot' => 3, 'recurrence' => ['kind' => 'daily']]]],
        ]];

        $native = $capability->toNative('zayata-m228', $public)['medication_reminders'];

        self::assertSame(
            [
                ['slot' => 1, 'hour' => 8, 'minute' => 0, 'enabled' => true],
                ['slot' => 3, 'hour' => 20, 'minute' => 30, 'enabled' => true],
            ],
            $native['plans'],
            'o nativo do M228',
        );
        self::assertSame($public, $capability->fromNative('zayata-m228', 'medication_reminders', $native));
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

    /** Um campo que só um fornecedor emite tem de estar declarado, senão o painel não o desenha. */
    public function testEveryWonlexExtensionIsDeclaredInTheMeta(): void
    {
        $capability = new MedicationRemindersCapability();
        $core = ['name', 'doseCount', 'startDate', 'endDate', 'times'];
        $meta = $capability->meta('wonlex-json');

        $plan = $capability->fromNative('wonlex-json', 'dnMedicationPlan', ['plans' => [[
            'drugType' => 1,
            'drugName' => 'Paracetamol',
            'drugDose' => 2,
            'drugUnit' => '3',
            'drugStartTime' => '2026-10-01',
            'drugEndTime' => '2026-10-10',
            'drugInterval' => 1,
            'drugTime' => ['alarmClock' => ['Morning' => '08:00'], 'checkboxes' => [0], 'radio' => 1],
        ]]])['plans'][0];

        self::assertSame(
            ['condition' => 'diabetes', 'doseUnit' => 'mg', 'intervalDays' => 1.0, 'mealTiming' => 'after_meal'],
            array_intersect_key($plan, array_flip(['condition', 'doseUnit', 'intervalDays', 'mealTiming'])),
            'a Wonlex emite os seus quatro campos próprios',
        );

        $undeclared = array_values(array_filter(
            array_keys($plan),
            static fn(string $field): bool => !in_array($field, $core, true) && !array_key_exists($field, $meta),
        ));

        self::assertSame([], $undeclared);
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
