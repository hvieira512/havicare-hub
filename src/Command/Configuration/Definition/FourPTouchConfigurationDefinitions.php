<?php

declare(strict_types=1);

namespace Hub\Command\Configuration\Definition;

final class FourPTouchConfigurationDefinitions
{
    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        $entry = ConfigurationDefinition::make(...);

        return [
            $entry('uploadInterval', 'UPLOAD', 'Intervalo de localização', 'number', ['intervalSeconds'], ['UPLOAD'], 'intervals', 10, options: ['min' => 60], help: 'Só envia com o relógio em movimento: parado 2 minutos, deixa de enviar.'),
            $entry('sosContacts', 'SOS', 'Contactos SOS', 'sos_contacts', ['numbers'], ['SOS'], 'contacts', 10, 3, help: 'Num SOS liga a um de cada vez, por esta ordem, até alguém atender.'),
            $entry('whitelistGroup1', 'WHITELIST1', 'Lista de chamadas autorizadas 1-5', 'call_whitelist', ['numbers'], ['WHITELIST1'], 'contacts', 40, 5),
            $entry('whitelistGroup2', 'WHITELIST2', 'Lista de chamadas autorizadas 6-10', 'call_whitelist', ['numbers'], ['WHITELIST2'], 'contacts', 50, 5),
            $entry('devicePassword', 'PW', 'Palavra-passe do dispositivo', 'text', ['password'], ['PW'], 'system', 10, help: 'Pedida nos comandos por SMS vindos de números que não o da central.'),
            $entry('languageTimezone', 'LZ', 'Idioma e fuso horário', 'languageTimezone', ['language', 'timeZone'], ['LZ'], 'system', 20, help: 'O idioma só muda se o firmware o tiver.'),
            $entry('sosSmsAlerts', 'SOSSMS', 'SMS em alarme SOS', 'toggle', ['enabled'], ['SOSSMS'], 'alerts', 10, help: 'Vai para os contactos SOS, sem a localização.'),
            $entry('lowBatterySmsAlerts', 'LOWBAT', 'SMS em bateria fraca', 'toggle', ['enabled'], ['LOWBAT'], 'alerts', 20, help: 'Vai para o número da central abaixo de 20%.'),
            $entry('removeWatchAlarm', 'REMOVE', 'Alarme ao retirar relógio', 'toggle', ['enabled'], ['REMOVE'], 'alerts', 30, help: 'Só em relógios com sensor de luz.'),
            $entry('removeWatchSmsAlerts', 'REMOVESMS', 'SMS ao retirar relógio', 'toggle', ['enabled'], ['REMOVESMS'], 'alerts', 40, help: 'Só em relógios com sensor de luz.'),
            $entry('fallDownAlert', 'FALLDOWN', 'Alerta de queda', 'dualToggle', ['enabled', 'callCenterOnFall'], ['FALLDOWN'], 'alerts', 50),
            $entry('fallDownSensitivity', 'LSSET', 'Sensibilidade de queda', 'fallSensitivityLevels', ['sensitivity'], ['LSSET'], 'alerts', 60, null, [
                'sensitivity' => [
                    ['value' => 1, 'label' => 'Máxima'],
                    ['value' => 2, 'label' => 'Muito Alta'],
                    ['value' => 3, 'label' => 'Alta'],
                    ['value' => 4, 'label' => 'Moderada'],
                    ['value' => 5, 'label' => 'Baixa'],
                    ['value' => 6, 'label' => 'Muito Baixa'],
                    ['value' => 7, 'label' => 'Quase Mínima'],
                    ['value' => 8, 'label' => 'Mínima'],
                ],
                'levels' => [
                    ['value' => 6, 'label' => '6 níveis'],
                    ['value' => 8, 'label' => '8 níveis'],
                ],
            ], help: '1 é a mais sensível. Android usa 6 níveis e RTOS 8; o fabricante sugere 4–5 e 5–6.'),
            $entry('takePills', 'TAKEPILLS', 'Lembrete de medicação com voz', 'takePills', ['reminderSettings', 'reminderText', 'voiceData'], ['TAKEPILLS'], 'alerts', 70, 3, [
                'frequency' => [
                    ['value' => 1, 'label' => 'Uma vez'],
                    ['value' => 2, 'label' => 'Diariamente'],
                    ['value' => 3, 'label' => 'Personalizado'],
                ],
            ], help: 'A gravação é cortada aos 15 segundos.'),
            $entry('healthAutoMeasurement', 'HEALTHAUTOSET', 'Medição automática de saúde', 'intervalToggle', ['enabled', 'intervalMinutes'], ['HEALTHAUTOSET'], 'health', 10, options: ['min' => 5], help: 'Ritmo cardíaco e tensão arterial; mínimo 5 minutos.'),
            $entry('walkTime', 'WALKTIME', 'Horário de contagem de passos', 'timeRanges', ['ranges'], ['WALKTIME'], 'health', 20, 3, help: 'Fora destes horários não conta passos.'),
            $entry('sleepTime', 'SLEEPTIME', 'Horário de sono', 'timeRange', ['range'], ['SLEEPTIME'], 'health', 30, help: 'Pode passar a meia-noite.'),
            $entry('bodyTemperatureInterval', 'bodytemp', 'Temperatura periódica', 'intervalHoursToggle', ['enabled', 'intervalHours'], ['bodytemp'], 'health', 40, help: 'Só em relógios com sensor de temperatura; não mede em modo noturno.'),
            $entry('makeCall', 'CALL', 'Fazer chamada', 'makeCall', ['phone'], ['CALL'], 'system', 5, transient: true),
            $entry('monitorNumber', 'MONITOR', 'Escuta remota', 'voiceMonitor', ['phone'], ['MONITOR'], 'system', 5, transient: true, help: 'Liga para este número e abre o microfone sem aviso no relógio.', confirm: 'Abrir a escuta remota agora?'),
            $entry('centerNumber', 'CENTER', 'Número da central', 'phone', ['phone'], ['CENTER'], 'contacts', 5, help: 'Recebe os SMS de alarme e pode configurar o relógio por SMS sem palavra-passe.'),
            $entry('pushMessage', 'MESSAGE', 'Enviar mensagem ao relógio', 'pushMessage', ['message'], ['MESSAGE'], 'system', 5, transient: true, help: 'Aparece no ecrã do relógio.'),
            $entry('resetCommand', 'RESET', 'Reiniciar dispositivo', 'action', [], ['RESET'], 'system', 5, transient: true, help: 'Reinicia sem mostrar nada a quem o usa.', confirm: 'O relógio fica sem comunicar enquanto arranca.'),
            $entry('powerOffCommand', 'POWEROFF', 'Desligar dispositivo', 'action', [], ['POWEROFF'], 'system', 5, transient: true, confirm: 'O relógio desliga-se e só volta a ligar no botão do próprio aparelho.'),
            $entry('findDeviceCommand', 'FIND', 'Fazer tocar', 'action', [], ['FIND'], 'system', 5, transient: true, help: 'Toca durante 1 minuto; pára ao carregar no botão.'),
            $entry('doNotDisturb', 'SILENCETIME', 'Não perturbar', 'timeRanges', ['ranges'], ['SILENCETIME'], 'system', 60, 4, help: 'Rejeita chamadas e bloqueia o ecrã nestes horários; o SOS continua a funcionar.'),
            // Estas duas perguntam em vez de mandar, e o rótulo delas é um nome: daí o verbo.
            // Sem `deviceStatus`: o `TS` pede-se no mosaico «Estado do dispositivo».
            $entry('alarmClock', 'REMIND', 'Despertadores', 'alarm_clock', ['alarms'], ['REMIND'], 'alerts', 5, 3, [
                'mode' => [
                    ['value' => 1, 'label' => 'Uma vez'],
                    ['value' => 2, 'label' => 'Todos os dias'],
                    ['value' => 3, 'label' => 'Personalizado'],
                ],
                'days' => [
                    ['value' => 1, 'label' => 'Seg'],
                    ['value' => 2, 'label' => 'Ter'],
                    ['value' => 3, 'label' => 'Qua'],
                    ['value' => 4, 'label' => 'Qui'],
                    ['value' => 5, 'label' => 'Sex'],
                    ['value' => 6, 'label' => 'Sáb'],
                    ['value' => 7, 'label' => 'Dom'],
                ],
            ]),
            $entry('phonebook', 'PHBX2', 'Lista telefónica', 'phonebook', ['contacts'], ['PHBX2', 'DPHBX', 'PHB', 'PHB2'], 'contacts', 55, 100),
            $entry('profile', 'profile', 'Perfil de som', 'soundProfile', ['mode'], ['profile'], 'system', 55, null, [
                'mode' => [
                    ['value' => 1, 'label' => 'Vibração e toque'],
                    ['value' => 2, 'label' => 'Só toque'],
                    ['value' => 3, 'label' => 'Só vibração'],
                    ['value' => 4, 'label' => 'Silêncio'],
                ],
            ]),
            $entry('rejectUnknownCalls', 'DEVREFUSEPHONESWITCH', 'Restringir chamadas recebidas', 'toggle', ['enabled'], ['DEVREFUSEPHONESWITCH'], 'contacts', 35, help: 'Recusa números desconhecidos; só atua com contactos SOS e lista telefónica preenchidos.'),
        ];
    }
}
