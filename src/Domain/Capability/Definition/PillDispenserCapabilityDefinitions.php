<?php

namespace Hub\Domain\Capability\Definition;

/**
 * As capacidades que o dispensador M228 produz pelo protocolo TCP. Só telemetria e eventos:
 * o plano de medicação e os comandos, que precisam de downlink, entram numa camada posterior.
 */
final class PillDispenserCapabilityDefinitions
{
    public static function all(): array
    {
        return [
            ['deviceType' => 'pill_dispenser', 'section' => 'telemetry', 'key' => 'battery', 'label' => 'Bateria', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'pill_dispenser', 'section' => 'telemetry', 'key' => 'medication_level', 'label' => 'Nível de medicação', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'pill_dispenser', 'section' => 'telemetry', 'key' => 'cells_remaining', 'label' => 'Células restantes', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'pill_dispenser', 'section' => 'telemetry', 'key' => 'temperature', 'label' => 'Temperatura', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'pill_dispenser', 'section' => 'telemetry', 'key' => 'humidity', 'label' => 'Humidade', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            // O sinal WiFi/GSM viaja aqui, à maneira dos relógios, e não numa capacidade própria.
            ['deviceType' => 'pill_dispenser', 'section' => 'telemetry', 'key' => 'device_status', 'label' => 'Estado do dispositivo', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'pill_dispenser', 'section' => 'alarms', 'key' => 'medication_intake', 'label' => 'Toma de medicação', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],
            ['deviceType' => 'pill_dispenser', 'section' => 'alarms', 'key' => 'device_fault', 'label' => 'Avaria', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],
            // A mesma chave do NCS e da pulseira: o botão de emergência é uma chamada de ajuda.
            ['deviceType' => 'pill_dispenser', 'section' => 'alarms', 'key' => 'help_call', 'label' => 'Chamada de ajuda', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],
        ];
    }
}
