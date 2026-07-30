<?php

namespace Hub\Command\Configuration\Definition;

final class FourPTouchConfigurationDefinitions
{
    public static function all(): array
    {
        $entry = ConfigurationDefinition::make(...);

        return [
            $entry('uploadInterval', 'UPLOAD', 'Intervalo de localização', 'number', ['intervalSeconds'], ['UPLOAD'], 'intervals', 10),
            $entry('sosNumber1', 'SOS1', 'SOS 1', 'phone', ['phone'], ['SOS1'], 'contacts', 10),
            $entry('sosNumber2', 'SOS2', 'SOS 2', 'phone', ['phone'], ['SOS2'], 'contacts', 20),
            $entry('sosNumber3', 'SOS3', 'SOS 3', 'phone', ['phone'], ['SOS3'], 'contacts', 30),
            $entry('whitelistGroup1', 'WHITELIST1', 'Lista branca 1-5', 'list', ['numbers'], ['WHITELIST1'], 'contacts', 40, 5),
            $entry('whitelistGroup2', 'WHITELIST2', 'Lista branca 6-10', 'list', ['numbers'], ['WHITELIST2'], 'contacts', 50, 5),
            $entry('monitorNumber', 'MONITOR', 'Número de monitorização', 'phone', ['phone'], ['MONITOR'], 'contacts', 60),
            $entry('devicePassword', 'PW', 'Palavra-passe do dispositivo', 'text', ['password'], ['PW'], 'system', 10),
            $entry('languageTimezone', 'LZ', 'Idioma e fuso horário', 'languageTimezone', ['language', 'timeZone'], ['LZ'], 'system', 20),
            $entry('sosSmsAlerts', 'SOSSMS', 'SMS em alarme SOS', 'toggle', ['enabled'], ['SOSSMS'], 'alerts', 10),
            $entry('lowBatterySmsAlerts', 'LOWBAT', 'SMS em bateria fraca', 'toggle', ['enabled'], ['LOWBAT'], 'alerts', 20),
            $entry('removeWatchAlarm', 'REMOVE', 'Alarme ao retirar relógio', 'toggle', ['enabled'], ['REMOVE'], 'alerts', 30),
            $entry('removeWatchSmsAlerts', 'REMOVESMS', 'SMS ao retirar relógio', 'toggle', ['enabled'], ['REMOVESMS'], 'alerts', 40),
            $entry('fallDownAlert', 'FALLDOWN', 'Alerta de queda', 'dualToggle', ['enabled', 'callCenterOnFall'], ['FALLDOWN'], 'alerts', 50),
            $entry('fallDownSensitivity', 'LSSET', 'Sensibilidade de queda', 'fallSensitivityLevels', ['sensitivity', 'levels'], ['LSSET'], 'alerts', 60, null, [
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
            $entry('takePills', 'TAKEPILLS', 'Lembrete de medicação com voz', 'takePills', ['reminderSettings', 'number', 'reminderText', 'voiceData'], ['TAKEPILLS'], 'alerts', 70, 3, [
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
            $entry('makeCall', 'CALL', 'Fazer chamada', 'makeCall', ['phone'], ['CALL'], 'system', 5) + ['transient' => true, 'kind' => 'request'],
            $entry('centerNumber', 'CENTER', 'Número central', 'phone', ['phone'], ['CENTER'], 'contacts', 5),
            $entry('pushMessage', 'MESSAGE', 'Enviar mensagem ao relógio', 'pushMessage', ['message'], ['MESSAGE'], 'system', 5) + ['transient' => true, 'kind' => 'request'],
            $entry('resetCommand', 'RESET', 'Reiniciar dispositivo', 'resetAction', [], ['RESET'], 'system', 5) + ['transient' => true, 'kind' => 'request'],
            $entry('powerOffCommand', 'POWEROFF', 'Desligar dispositivo', 'resetAction', [], ['POWEROFF'], 'system', 5) + ['transient' => true, 'kind' => 'request'],
            $entry('findDeviceCommand', 'FIND', 'Localizar dispositivo', 'resetAction', [], ['FIND'], 'system', 5) + ['transient' => true, 'kind' => 'request'],
            $entry('doNotDisturb', 'SILENCETIME', 'Não perturbar', 'toggle', ['enabled'], ['SILENCETIME'], 'system', 60),
            $entry('firmwareVersion', 'VERNO', 'Versão de firmware', 'requestAction', [], ['VERNO'], 'system', 5) + ['transient' => true, 'kind' => 'request'],
            $entry('deviceStatus', 'TS', 'Estado do dispositivo', 'requestAction', [], ['TS'], 'system', 5) + ['transient' => true, 'kind' => 'request'],
            $entry('alarmClock', 'REMIND', 'Alarmes', 'alarms', ['alarms'], ['REMIND'], 'alerts', 5, 3, [
                'mode' => [
                    ['value' => 1, 'label' => 'Uma vez'],
                    ['value' => 2, 'label' => 'Todos os dias'],
                    ['value' => 3, 'label' => 'Personalizado'],
                ],
                'days' => [
                    ['value' => 0, 'label' => 'Dom'],
                    ['value' => 1, 'label' => 'Seg'],
                    ['value' => 2, 'label' => 'Ter'],
                    ['value' => 3, 'label' => 'Qua'],
                    ['value' => 4, 'label' => 'Qui'],
                    ['value' => 5, 'label' => 'Sex'],
                    ['value' => 6, 'label' => 'Sab'],
                ],
            ]),
            $entry('phonebook', 'PHB', 'Lista telefónica', 'contacts', ['contacts'], ['PHB', 'PHB2'], 'contacts', 55, 5),
            $entry('profile', 'profile', 'Perfil de som', 'soundProfile', ['mode'], ['profile'], 'system', 55, null, [
                'mode' => [
                    ['value' => 1, 'label' => 'Vibração e toque'],
                    ['value' => 2, 'label' => 'Só toque'],
                    ['value' => 3, 'label' => 'Só vibração'],
                    ['value' => 4, 'label' => 'Silêncio'],
                ],
            ]),
            $entry('rejectUnknownCalls', 'DEVREFUSEPHONESWITCH', 'Lista branca ativa', 'toggle', ['enabled'], ['DEVREFUSEPHONESWITCH'], 'contacts', 35),
        ];
    }
}
