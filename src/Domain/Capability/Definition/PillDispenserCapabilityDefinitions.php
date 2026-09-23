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
            ['deviceType' => 'pill_dispenser', 'section' => 'telemetry', 'key' => 'battery', 'label' => 'Bateria', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            // O nível de medicação viaja como campo desta: é o juízo do aparelho sobre a
            // mesma contagem, e sozinho não trazia número nenhum.
            ['deviceType' => 'pill_dispenser', 'section' => 'telemetry', 'key' => 'cells_remaining', 'label' => 'Células restantes', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'pill_dispenser', 'section' => 'telemetry', 'key' => 'temperature', 'label' => 'Temperatura', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'pill_dispenser', 'section' => 'telemetry', 'key' => 'humidity', 'label' => 'Humidade', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            // Aberta quer dizer que a medicação está acessível: é um estado sobre que se age.
            ['deviceType' => 'pill_dispenser', 'section' => 'telemetry', 'key' => 'lid_state', 'label' => 'Tampa', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            // A mesma `connectivity` que os gateways publicam, e não um formato só deste.
            ['deviceType' => 'pill_dispenser', 'section' => 'telemetry', 'key' => 'connectivity', 'label' => 'Conectividade', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            // Chega no pacote de registo e em mais lado nenhum, e por isso não é pedível: a
            // única altura em que muda é depois de uma actualização, que acaba em religar.
            ['deviceType' => 'pill_dispenser', 'section' => 'telemetry', 'key' => 'firmware_version', 'label' => 'Versão do firmware', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            // Sem `device_status` e sem CCID: cada leitura do `0x07` pede-se por si, e o CCID
            // nunca muda. O estado dos nove alarmes é a única leitura da toma que chega em
            // claro — o `0x03`, que traz a hora e a célula, vem cifrado.
            ['deviceType' => 'pill_dispenser', 'section' => 'telemetry', 'key' => 'medication_alarm_status', 'label' => 'Estado dos alarmes', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'pill_dispenser', 'section' => 'alarms', 'key' => 'medication_intake', 'label' => 'Toma de medicação', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],
            ['deviceType' => 'pill_dispenser', 'section' => 'alarms', 'key' => 'device_fault', 'label' => 'Avaria', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],
            // Um alerta: o aparelho compara o que mede com a gama do fabricante e só fala
            // quando ela é ultrapassada.
            ['deviceType' => 'pill_dispenser', 'section' => 'alarms', 'key' => 'storage_environment', 'label' => 'Medicação mal conservada', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],
            // O que acontece entre dois retratos dos nove. É o único sinal de uma dose
            // falhada: não houve toma, e por isso não há `medication_intake` nenhum.
            ['deviceType' => 'pill_dispenser', 'section' => 'alarms', 'key' => 'medication_alarm_change', 'label' => 'Alteração de dose', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],
            // A mesma chave do NCS e da pulseira: o botão de emergência é uma chamada de ajuda.
            ['deviceType' => 'pill_dispenser', 'section' => 'alarms', 'key' => 'help_call', 'label' => 'Chamada de ajuda', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],

            // O que se configura. O plano reaproveita a chave que os relógios já usam.
            ['deviceType' => 'pill_dispenser', 'section' => 'health', 'key' => 'medication_reminders', 'label' => 'Plano de medicação', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'pill_dispenser', 'section' => 'health', 'key' => 'medication_period', 'label' => 'Período do plano', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'pill_dispenser', 'section' => 'health', 'key' => 'early_dispense', 'label' => 'Toma antecipada', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'pill_dispenser', 'section' => 'health', 'key' => 'child_lock', 'label' => 'Bloqueio de criança', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'pill_dispenser', 'section' => 'alarms', 'key' => 'alarm_volume', 'label' => 'Volume', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'pill_dispenser', 'section' => 'alarms', 'key' => 'alarm_ringtone', 'label' => 'Tipo de toque', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'pill_dispenser', 'section' => 'alarms', 'key' => 'do_not_disturb', 'label' => 'Não incomodar', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'pill_dispenser', 'section' => 'settings_system', 'key' => 'device_language', 'label' => 'Idioma do ecrã', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'pill_dispenser', 'section' => 'settings_system', 'key' => 'time_zone', 'label' => 'Fuso horário', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],

            // O que se pede. Uma acção pede-se, não se configura.
            ['deviceType' => 'pill_dispenser', 'section' => 'settings_system', 'key' => 'sync_configuration', 'label' => 'Sincronizar configuração', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            // O que o firmware anuncia servir. Não é um valor que se escolha, é o que o
            // aparelho sabe fazer — e é o que evita manter uma tabela por modelo.
            ['deviceType' => 'pill_dispenser', 'section' => 'settings_system', 'key' => 'supported_configuration', 'label' => 'Que configurações este aparelho aceita', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'pill_dispenser', 'section' => 'settings_system', 'key' => 'supported_status', 'label' => 'Que leituras este aparelho sabe dar', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'pill_dispenser', 'section' => 'settings_system', 'key' => 'supported_control', 'label' => 'Que ordens este aparelho obedece', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            // Os dois tempos decidem se uma dose por tomar chega a alguém como alerta, e as
            // células carregadas são o que permite ao aparelho avisar que está a acabar.
            ['deviceType' => 'pill_dispenser', 'section' => 'health', 'key' => 'retrieval_warning', 'label' => 'Avisar de atraso ao fim de', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'pill_dispenser', 'section' => 'health', 'key' => 'retrieval_timeout', 'label' => 'Dar como falhada ao fim de', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'pill_dispenser', 'section' => 'health', 'key' => 'loaded_cells', 'label' => 'Compartimentos carregados', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'pill_dispenser', 'section' => 'health', 'key' => 'dispense_now', 'label' => 'Dispensar agora', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'pill_dispenser', 'section' => 'alarms', 'key' => 'mute_alarm', 'label' => 'Silenciar o alarme a tocar', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'pill_dispenser', 'section' => 'settings_system', 'key' => 'calibrate_clock', 'label' => 'Acertar o relógio do aparelho', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'pill_dispenser', 'section' => 'settings_system', 'key' => 'reset_tray', 'label' => 'Repor o prato', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'pill_dispenser', 'section' => 'settings_system', 'key' => 'restart_device', 'label' => 'Reiniciar', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            // A reposição de fábrica (`0xA002`) não é anunciada: devolve o aparelho ao
            // servidor do fornecedor e perde-se o controlo dele daqui.
        ];
    }
}
