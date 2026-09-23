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
            // A tampa do prato: aberta quer dizer que a medicação está acessível, e é um
            // estado sobre que alguém age. Estava enfiada dentro do estado do dispositivo,
            // entre dois números de sinal, onde ninguém a lia.
            ['deviceType' => 'pill_dispenser', 'section' => 'telemetry', 'key' => 'lid_state', 'label' => 'Tampa', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            // A ligação à rede é a mesma `connectivity` que os gateways publicam: um formato
            // só deste aparelho obrigava quem integra a conhecer mais um para ler a mesma
            // grandeza.
            ['deviceType' => 'pill_dispenser', 'section' => 'telemetry', 'key' => 'connectivity', 'label' => 'Conectividade', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            // O `device_status` não publica nada: é só o botão que pede ao aparelho o estado
            // que ele tem agora, em vez de se esperar pelo próximo heartbeat. A resposta
            // chega repartida pelas capacidades de cima, que é onde se lê.
            ['deviceType' => 'pill_dispenser', 'section' => 'telemetry', 'key' => 'device_status', 'label' => 'Estado do dispositivo', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            // O CCID do cartão SIM não está aqui de propósito: é um identificador que nunca
            // muda, ninguém o consulta na dashboard, e quem precise dele vai buscá-lo à ficha
            // do dispositivo. O adaptador continua a descodificá-lo, mas descodificar e
            // publicar são decisões separadas.
            // O estado de toma dos nove alarmes: a única leitura da toma que chega em claro,
            // porque o `0x03` que traz a hora e a célula vem cifrado. Não é pedível à parte —
            // viaja no mesmo `0x07` que o «Atualizar estado» já manda.
            ['deviceType' => 'pill_dispenser', 'section' => 'telemetry', 'key' => 'medication_alarm_status', 'label' => 'Estado dos alarmes', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'pill_dispenser', 'section' => 'alarms', 'key' => 'medication_intake', 'label' => 'Toma de medicação', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],
            ['deviceType' => 'pill_dispenser', 'section' => 'alarms', 'key' => 'device_fault', 'label' => 'Avaria', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],
            // Um alerta e não uma leitura: o aparelho compara a temperatura e a humidade que
            // mede com a gama que o fabricante dá como boa, e isto só fala quando ela é
            // ultrapassada. Como telemetria, enchia a lista com linhas a dizer «Dentro da
            // gama», que é o normal e que ninguém lê.
            ['deviceType' => 'pill_dispenser', 'section' => 'alarms', 'key' => 'storage_environment', 'label' => 'Medicação mal conservada', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],
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
            ['deviceType' => 'pill_dispenser', 'section' => 'settings_system', 'key' => 'device_language', 'label' => 'Idioma', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'pill_dispenser', 'section' => 'settings_system', 'key' => 'time_zone', 'label' => 'Fuso horário', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],

            // O que se pede. Uma acção pede-se, não se configura.
            ['deviceType' => 'pill_dispenser', 'section' => 'settings_system', 'key' => 'sync_configuration', 'label' => 'Sincronizar configuração', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            // O que o firmware anuncia servir. Não é um valor que se escolha, é o que o
            // aparelho sabe fazer — e é o que evita manter uma tabela por modelo.
            ['deviceType' => 'pill_dispenser', 'section' => 'settings_system', 'key' => 'supported_configuration', 'label' => 'Parâmetros de configuração', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'pill_dispenser', 'section' => 'settings_system', 'key' => 'supported_status', 'label' => 'Parâmetros de estado', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'pill_dispenser', 'section' => 'settings_system', 'key' => 'supported_control', 'label' => 'Parâmetros de controlo', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            // Os dois tempos decidem se uma dose por tomar chega a alguém como alerta, e as
            // células carregadas são o que permite ao aparelho avisar que está a acabar.
            ['deviceType' => 'pill_dispenser', 'section' => 'health', 'key' => 'retrieval_warning', 'label' => 'Avisar de atraso ao fim de', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'pill_dispenser', 'section' => 'health', 'key' => 'retrieval_timeout', 'label' => 'Dar como falhada ao fim de', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'pill_dispenser', 'section' => 'health', 'key' => 'loaded_cells', 'label' => 'Compartimentos carregados', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'pill_dispenser', 'section' => 'health', 'key' => 'dispense_now', 'label' => 'Dispensar agora', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'pill_dispenser', 'section' => 'alarms', 'key' => 'mute_alarm', 'label' => 'Silenciar', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'pill_dispenser', 'section' => 'settings_system', 'key' => 'calibrate_clock', 'label' => 'Calibrar relógio', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'pill_dispenser', 'section' => 'settings_system', 'key' => 'reset_tray', 'label' => 'Repor o prato', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'pill_dispenser', 'section' => 'settings_system', 'key' => 'restart_device', 'label' => 'Reiniciar', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            // A reposição de fábrica (`0xA002`) não é anunciada: devolve o aparelho ao
            // servidor do fornecedor e perde-se o controlo dele daqui.
        ];
    }
}
