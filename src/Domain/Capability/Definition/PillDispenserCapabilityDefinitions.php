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
                    'battery' => 'Bateria',
                    // O nível de medicação viaja como campo desta: é o juízo do aparelho
                    // sobre a mesma contagem, e sozinho não trazia número nenhum.
                    'cells_remaining' => 'Células restantes',
                    // O ar onde o aparelho está, e portanto onde a medicação está guardada.
                    // A spec dá `0x810E` como INT8S de -40 a 120 graus inteiros: não é um
                    // sensor corporal, e por isso não partilha a chave `temperature`.
                    'ambient_temperature' => 'Temperatura ambiente',
                    'ambient_humidity' => 'Humidade ambiente',
                    // A mesma `connectivity` que os gateways publicam, e não um formato só
                    // deste.
                    'connectivity' => 'Conectividade',
                    // Chega no pacote de registo e em mais lado nenhum: a única altura em
                    // que muda é depois de uma actualização, que acaba em religar.
                    'firmware_version' => 'Versão do firmware',
                    // O estado dos nove alarmes é a única leitura da toma que chega em
                    // claro — o `0x03`, que traz a hora e a célula, vem cifrado.
                    'medication_alarm_status' => 'Estado dos alarmes',
                ],
                // O `0x07` pede as `STATUS_TAGS` todas de uma vez, e a resposta enche as
                // leituras acima em vez de trazer valor próprio. Por isso é o único pedível
                // daqui: as outras não se pedem sozinhas.
                'measurementOnRequest' => [
                    'device_status' => 'Estado do dispositivo',
                ],
            ],
            'health' => [
                'setting' => [
                    'early_dispense' => 'Toma antecipada',
                    // Sem ele, uma dose falhada deixa de estar acessível — e o desfecho
                    // `abnormal` do evento de toma nunca chega a existir.
                    'missed_dispense' => 'Dispensar depois de falhar',
                    'child_lock' => 'Bloqueio de criança',
                    // Os dois tempos decidem se uma dose por tomar chega a alguém como
                    // alerta, e as células carregadas são o que permite ao aparelho avisar
                    // que está a acabar.
                    'retrieval_warning' => 'Avisar de atraso ao fim de',
                    'retrieval_timeout' => 'Dar como falhada ao fim de',
                    'loaded_cells' => 'Carregado até ao compartimento',
                ],
                'action' => [
                    'dispense_now' => 'Dispensar agora',
                ],
            ],
            'alarms' => [
                'setting' => [
                    // O plano reaproveita a chave que os relógios já usam, e fica com eles
                    // em Alarmes: é o horário das doses, e é o que faz o aparelho tocar.
                    'medication_reminders' => 'Plano de medicação',
                    'medication_period' => 'Período do plano',
                    'alarm_volume' => 'Volume',
                    'alarm_ringtone' => 'Tipo de toque',
                    // Fica ao lado do evento que produz, a chamada de ajuda, e não em
                    // Sistema: é segurança e não configuração de aparelho.
                    'emergency_call' => 'Chamada de emergência',
                ],
                'action' => [
                    'mute_alarm' => 'Silenciar o alarme a tocar',
                ],
                'event' => [
                    'medication_intake' => 'Toma de medicação',
                    'device_fault' => 'Avaria',
                    // O aparelho compara o que mede com a gama do fabricante e só fala
                    // quando ela é ultrapassada.
                    'storage_environment' => 'Medicação mal conservada',
                    // O que acontece entre dois retratos dos nove. É o único sinal de uma
                    // dose falhada: não houve toma, e por isso não há `medication_intake`.
                    'medication_alarm_change' => 'Alteração de dose',
                    // A mesma chave do NCS e da pulseira: o botão de emergência é uma
                    // chamada de ajuda.
                    'help_call' => 'Chamada de ajuda',
                ],
            ],
            'settings_system' => [
                'setting' => [
                    'device_language' => 'Idioma do ecrã',
                    'date_format' => 'Formato da data',
                    'time_format' => 'Formato da hora',
                    'auto_clock' => 'Acertar-se sozinho',
                    'key_tone' => 'Som das teclas',
                    'time_zone' => 'Fuso horário',
                    // Com os relógios: é uma janela de silêncio do aparelho inteiro, e não
                    // uma definição de um alarme em particular.
                    'do_not_disturb' => 'Não incomodar',
                ],
                // A reposição de fábrica (`0xA002`) não é anunciada: devolve o aparelho ao
                // servidor do fornecedor e perde-se o controlo dele daqui.
                'action' => [
                    'sync_configuration' => 'Sincronizar configuração',
                    'calibrate_clock' => 'Acertar o relógio do aparelho',
                    'reset_tray' => 'Repor o prato',
                    'restart_device' => 'Reiniciar dispositivo',
                ],
            ],
        ];
    }
}
