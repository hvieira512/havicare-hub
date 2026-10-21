<?php

declare(strict_types=1);

namespace Hub\Domain\Capability\Definition;

/**
 * As capacidades que o dispensador M228 produz e aceita pelo protocolo TCP.
 */
final class PillDispenserCapabilityDefinitions extends CapabilityDefinitions
{
    protected static function deviceType(): string
    {
        return 'pill_dispenser';
    }

    /**
     * O `0x07` pede as `STATUS_TAGS` todas e a resposta enche as leituras acima: o
     * `device_status` não é anunciado, chega pelo comando que o pede.
     */
    protected static function publishedBy(): array
    {
        return ['device_status' => []];
    }

    protected static function rows(): array
    {
        return [
            'telemetry' => [
                // Nenhuma se pede sozinha: o `0x07` pede sempre as `STATUS_TAGS` todas, e é
                // o `device_status` que carrega o botão.
                'measurement' => [
                    'battery',
                    // O nível de medicação viaja como campo desta: é o juízo do aparelho
                    // sobre a mesma contagem, e sozinho não trazia número nenhum.
                    'cells_remaining',
                    // O ar onde a medicação está guardada: o `0x810E` é INT8S de -40 a 120 graus, não é sensor
                    // corporal, e por isso não partilha a chave `temperature`.
                    'ambient_temperature',
                    'ambient_humidity',
                    // A mesma `connectivity` que os gateways publicam, e não um formato só
                    // deste.
                    'connectivity',
                    // Chega no pacote de registo e em mais lado nenhum: a única altura em
                    // que muda é depois de uma actualização, que acaba em religar.
                    'firmware_version',
                    // O estado dos nove alarmes vem na resposta ao `0x07`; a toma, com a hora e a
                    // célula, é o evento `0x03`.
                    'medication_alarm_status',
                ],
                // O `0x07` pede as `STATUS_TAGS` todas e a resposta enche as leituras acima: é o único
                // pedível daqui.
                'measurementOnRequest' => [
                    'device_status',
                ],
            ],
            'health' => [
                'setting' => [
                    'early_dispense',
                    // Sem ele, uma dose falhada deixa de estar acessível — e o desfecho
                    // `abnormal` do evento de toma nunca chega a existir.
                    'missed_dispense',
                    'child_lock',
                    // Os dois tempos decidem se uma dose por tomar chega como alerta, e as células carregadas
                    // deixam o aparelho avisar que está a acabar.
                    'retrieval_warning',
                    'retrieval_timeout',
                    'loaded_cells',
                ],
                'action' => [
                    'dispense_now',
                ],
            ],
            'reminders' => [
                'setting' => [
                    // O plano reaproveita a chave que os relógios já usam: é o horário das
                    // doses, e é o que faz o aparelho tocar.
                    'medication_reminders',
                    'medication_period',
                    'alarm_volume',
                    'alarm_ringtone',
                ],
                'action' => [
                    'mute_alarm',
                ],
            ],
            'alarms' => [
                'setting' => [
                    // Fica ao lado do evento que produz, a chamada de ajuda, e não em
                    // Sistema: é segurança e não configuração de aparelho.
                    'emergency_call',
                ],
                'event' => [
                    'medication_intake',
                    'device_fault',
                    // O aparelho compara o que mede com a gama do fabricante e só fala
                    // quando ela é ultrapassada.
                    'storage_environment',
                    // O que acontece entre dois retratos dos nove. É o único sinal de uma
                    // dose falhada: não houve toma, e por isso não há `medication_intake`.
                    'medication_alarm_change',
                    // A mesma chave do NCS e da pulseira: o botão de emergência é uma
                    // chamada de ajuda.
                    'help_call',
                    'low_battery',
                ],
            ],
            'settings_system' => [
                'setting' => [
                    'device_language',
                    'date_format',
                    'time_format',
                    'auto_clock',
                    'key_tone',
                    'time_zone',
                    // Com os relógios: é uma janela de silêncio do aparelho inteiro, e não
                    // uma definição de um alarme em particular.
                    'do_not_disturb',
                ],
                // A reposição de fábrica (`0xA002`) não é anunciada: devolve o aparelho ao
                // servidor do fornecedor e perde-se o controlo dele daqui.
                'action' => [
                    'sync_configuration',
                    'calibrate_clock',
                    'reset_tray',
                    'restart_device',
                ],
            ],
        ];
    }
}
