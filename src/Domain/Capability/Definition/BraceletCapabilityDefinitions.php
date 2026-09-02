<?php

namespace Hub\Domain\Capability\Definition;

final class BraceletCapabilityDefinitions
{
    /**
     * O botão é uma capacidade só e não uma por modo de toque: os modos configuram-se no
     * aparelho, e o tipo de toque viaja no payload do evento. Separá-los aqui punha três
     * interruptores na matriz de capacidades para o que é uma funcionalidade física.
     *
     * @return list<array{deviceType: string, section: string, key: string, label: string, isTelemetry: bool, isConfigurable: bool, isRequestable: bool, isEvent?: bool}>
     */
    public static function all(): array
    {
        return [
            ['deviceType' => 'bracelet', 'section' => 'telemetry', 'key' => 'battery', 'label' => 'Bateria', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'bracelet', 'section' => 'telemetry', 'key' => 'motion', 'label' => 'Movimento', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            // Sai por avistamento, e não do aparelho: é a força com que cada gateway o ouve,
            // que é o que sustenta os alarmes de proximidade. `isRequestable` false porque
            // uma pulseira BLE só transmite -- não há o que lhe pedir.
            ['deviceType' => 'bracelet', 'section' => 'telemetry', 'key' => 'proximity', 'label' => 'Proximidade', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'bracelet', 'section' => 'alarms', 'key' => 'help_call', 'label' => 'Chamada de ajuda', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],
        ];
    }
}
