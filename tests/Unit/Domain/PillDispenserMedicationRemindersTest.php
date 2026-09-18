<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use Hub\Domain\Capability\CapabilityRegistry;
use PHPUnit\Framework\TestCase;

/**
 * O `medication_reminders` é uma capacidade com contrato próprio, partilhada com os relógios.
 * Declarar a configuração no catálogo do dispensador não chega: sem o protocolo no contrato,
 * o `toNative` rebenta no momento de gravar — o formulário aparece, o utilizador preenche os
 * nove alarmes, carrega em Enviar e leva com um erro.
 */
final class PillDispenserMedicationRemindersTest extends TestCase
{
    public function testTheContractKnowsTheDispenserProtocol(): void
    {
        $registry = new CapabilityRegistry();

        self::assertTrue(
            $registry->supportsProtocol('medication_reminders', 'zayata-m228'),
            'o contrato tem de aceitar o protocolo do dispensador',
        );
    }

    public function testThePlanSurvivesTheRoundTripThroughTheContract(): void
    {
        $registry = new CapabilityRegistry();
        $plan = ['plans' => [
            ['hour' => 8, 'minute' => 30, 'enabled' => true],
            ['hour' => 20, 'minute' => 5, 'enabled' => false],
        ]];

        $native = $registry->toNative('zayata-m228', 'medication_reminders', $plan);

        self::assertArrayHasKey('medication_reminders', $native);
        self::assertSame($plan['plans'], $native['medication_reminders']['plans']);

        // E de volta: é o que a dashboard lê para desenhar o formulário do que já está
        // gravado, e tem de dar o mesmo que se enviou.
        self::assertSame(
            $plan,
            $registry->fromNative('zayata-m228', 'medication_reminders', 'medication_reminders', $native['medication_reminders']),
        );
    }

    public function testAnEmptyPlanIsAValidPlan(): void
    {
        $registry = new CapabilityRegistry();

        // Vazio quer dizer os nove alarmes desligados, e é o ponto de partida de um aparelho
        // que nunca foi configurado.
        self::assertSame(
            ['plans' => []],
            $registry->toNative('zayata-m228', 'medication_reminders', ['plans' => []])['medication_reminders'],
        );
    }
}
