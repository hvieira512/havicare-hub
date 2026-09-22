<?php

namespace Hub\Command\Configuration\Definition;

final class FourPTouchConfigurationDefinitions
{
    public static function all(): array
    {
        $entry = ConfigurationDefinition::make(...);

        return [
            $entry('uploadInterval', 'UPLOAD', 'Intervalo de localização', 'number', ['intervalSeconds'], ['UPLOAD'], 'intervals', 10),
            $entry('sosContacts', 'SOS', 'Contactos SOS', 'sos_contacts', ['numbers'], ['SOS'], 'contacts', 10, 3),
            $entry('whitelistGroup1', 'WHITELIST1', 'Lista de chamadas autorizadas 1-5', 'call_whitelist', ['numbers'], ['WHITELIST1'], 'contacts', 40, 5),
            $entry('whitelistGroup2', 'WHITELIST2', 'Lista de chamadas autorizadas 6-10', 'call_whitelist', ['numbers'], ['WHITELIST2'], 'contacts', 50, 5),
            $entry('devicePassword', 'PW', 'Palavra-passe do dispositivo', 'text', ['password'], ['PW'], 'system', 10),
            $entry('languageTimezone', 'LZ', 'Idioma e fuso horário', 'languageTimezone', ['language', 'timeZone'], ['LZ'], 'system', 20),
            $entry('sosSmsAlerts', 'SOSSMS', 'SMS em alarme SOS', 'toggle', ['enabled'], ['SOSSMS'], 'alerts', 10),
            $entry('lowBatterySmsAlerts', 'LOWBAT', 'SMS em bateria fraca', 'toggle', ['enabled'], ['LOWBAT'], 'alerts', 20),
            $entry('removeWatchAlarm', 'REMOVE', 'Alarme ao retirar relógio', 'toggle', ['enabled'], ['REMOVE'], 'alerts', 30),
            $entry('removeWatchSmsAlerts', 'REMOVESMS', 'SMS ao retirar relógio', 'toggle', ['enabled'], ['REMOVESMS'], 'alerts', 40),
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
            ]),
            $entry('takePills', 'TAKEPILLS', 'Lembrete de medicação com voz', 'takePills', ['reminderSettings', 'reminderText', 'voiceData'], ['TAKEPILLS'], 'alerts', 70, 3, [
                'frequency' => [
                    ['value' => 1, 'label' => 'Uma vez'],
                    ['value' => 2, 'label' => 'Diariamente'],
                    ['value' => 3, 'label' => 'Personalizado'],
                ],
            ]),
            $entry('healthAutoMeasurement', 'HEALTHAUTOSET', 'Medição automática de saúde', 'intervalToggle', ['enabled', 'intervalMinutes'], ['HEALTHAUTOSET'], 'health', 10),
            $entry('walkTime', 'WALKTIME', 'Janela de pedómetro', 'timeRanges', ['ranges'], ['WALKTIME'], 'health', 20, 3),
            $entry('sleepTime', 'SLEEPTIME', 'Deteção de sono e rotação', 'timeRange', ['range'], ['SLEEPTIME'], 'health', 30),
            $entry('bodyTemperatureInterval', 'bodytemp', 'Temperatura periódica', 'intervalHoursToggle', ['enabled', 'intervalHours'], ['bodytemp'], 'health', 40),
            $entry('makeCall', 'CALL', 'Fazer chamada', 'makeCall', ['phone'], ['CALL'], 'system', 5, transient: true),
            $entry('monitorNumber', 'MONITOR', 'Número de monitorização', 'voiceMonitor', ['phone'], ['MONITOR'], 'system', 5, transient: true, confirm: 'O relógio liga de imediato para este número e abre o microfone, sem mostrar nada a quem o traz no pulso.'),
            $entry('centerNumber', 'CENTER', 'Número da central', 'phone', ['phone'], ['CENTER'], 'contacts', 5),
            $entry('pushMessage', 'MESSAGE', 'Enviar mensagem ao relógio', 'pushMessage', ['message'], ['MESSAGE'], 'system', 5, transient: true),
            $entry('resetCommand', 'RESET', 'Reiniciar dispositivo', 'action', [], ['RESET'], 'system', 5, transient: true, confirm: 'O relógio fica sem comunicar enquanto arranca.'),
            $entry('powerOffCommand', 'POWEROFF', 'Desligar dispositivo', 'action', [], ['POWEROFF'], 'system', 5, transient: true, confirm: 'O relógio desliga-se e só volta a ligar no botão do próprio aparelho.'),
            $entry('findDeviceCommand', 'FIND', 'Localizar dispositivo', 'action', [], ['FIND'], 'system', 5, transient: true),
            $entry('doNotDisturb', 'SILENCETIME', 'Não perturbar', 'toggle', ['enabled'], ['SILENCETIME'], 'system', 60),
            $entry('firmwareVersion', 'VERNO', 'Versão de firmware', 'action', [], ['VERNO'], 'system', 5, transient: true),
            $entry('deviceStatus', 'TS', 'Estado do dispositivo', 'action', [], ['TS'], 'system', 5, transient: true),
            $entry('alarmClock', 'REMIND', 'Alarmes', 'alarm_clock', ['alarms'], ['REMIND'], 'alerts', 5, 3, [
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
                    ['value' => 6, 'label' => 'Sab'],
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
            $entry('rejectUnknownCalls', 'DEVREFUSEPHONESWITCH', 'Restringir chamadas recebidas', 'whitelist_enabled', ['enabled'], ['DEVREFUSEPHONESWITCH'], 'contacts', 35),
        ];
    }
}
