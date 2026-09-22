<?php

namespace Hub\Command\Configuration\Definition;

final class WonlexConfigurationDefinitions
{
    public static function all(): array
    {
        $entry = ConfigurationDefinition::make(...);

        return [
            $entry('locationInterval', 'locationInterval', 'Intervalo de localização', 'number', ['intervalTime'], ['locationInterval'], 'intervals', 10),
            $entry('deviceMeasuringFrequency', 'deviceMeasuringFrequency', 'Frequência de medições', 'json', ['configs'], ['deviceMeasuringFrequency'], 'intervals', 90),
            self::measurementInterval('wonlexHeartRateInterval', 'Intervalo de frequência cardíaca', 10),
            self::measurementInterval('wonlexBPInterval', 'Intervalo de tensão arterial', 20),
            self::measurementInterval('wonlexBOInterval', 'Intervalo de oxigénio no sangue', 30),
            self::measurementInterval('wonlexBodyTemperatureInterval', 'Intervalo de temperatura', 40),
            self::measurementInterval('wonlexStepInterval', 'Intervalo de passos', 50),
            self::measurementInterval('wonlexBreatheInterval', 'Intervalo de frequência respiratória', 60),
            self::measurementInterval('wonlexECGInterval', 'Intervalo de ECG', 70),
            self::measurementInterval('wonlexHRVInterval', 'Intervalo de VFC', 80),
            self::measurementInterval('wonlexPPGInterval', 'Intervalo de PPG', 90),
            self::measurementInterval('wonlexRRInterval', 'Intervalo de RR', 100),
            $entry('deviceConfig', 'deviceConfig', 'Configuração do dispositivo', 'json', ['configs'], ['deviceConfig'], 'system', 90),
            $entry('wonlexStepTarget', 'deviceConfig', 'Meta de passos', 'number', ['steps'], ['deviceConfig'], 'health', 10),
            $entry('wonlexContinuousBOCheck', 'deviceConfig', 'Oxigénio contínuo em repouso', 'toggle', ['switchState'], ['deviceConfig'], 'health', 20),
            $entry('wonlexContinuousHRSwitch', 'deviceConfig', 'Frequência cardíaca contínua', 'toggle', ['switchState'], ['deviceConfig'], 'health', 30),
            $entry('wonlexPPGBPTrend', 'deviceConfig', 'Tendência PPG da pressão arterial', 'toggle', ['switchState'], ['deviceConfig'], 'health', 40),
            $entry('wonlexContinuousTempSwitch', 'deviceConfig', 'Temperatura automática', 'toggle', ['switchState'], ['deviceConfig'], 'health', 50),
            $entry('wonlexSleepIntervalOrSwitch', 'deviceConfig', 'Definições de sono', 'wonlexSleepSettings', ['switchState', 'sleepStartTime', 'sleepEndTime', 'sleepTarget'], ['deviceConfig'], 'health', 60),
            $entry('wonlexBloodOxygenWarn', 'deviceConfig', 'Alerta de oxigénio baixo', 'wonlexReminderThreshold', ['switchState', 'reminderValue'], ['deviceConfig'], 'alerts', 40),
            $entry('wonlexTemperatureExceedRemind', 'deviceConfig', 'Alerta de temperatura alta', 'wonlexReminderThreshold', ['switchState', 'RemindValue'], ['deviceConfig'], 'alerts', 50),
            $entry('wonlexTemperatureBelowRemind', 'deviceConfig', 'Alerta de temperatura baixa', 'wonlexReminderThreshold', ['switchState', 'RemindValue'], ['deviceConfig'], 'alerts', 60),
            $entry('wonlexBPEarlyWarning', 'deviceConfig', 'Alerta de tensão arterial', 'wonlexBloodPressureWarning', ['switchState', 'hpWarn', 'LPWarn'], ['deviceConfig'], 'alerts', 70),
            $entry('wonlexHeartRateHighRemind', 'deviceConfig', 'Alerta de frequência cardíaca alta', 'wonlexHeartRateRange', ['switchState', 'remindValue', 'exerciseSwitchState', 'exerciseHRMin', 'exerciseHRMax', 'exerciseRemindValue'], ['deviceConfig'], 'alerts', 80),
            $entry('wonlexHeartRateLowRemind', 'deviceConfig', 'Alerta de frequência cardíaca baixa', 'wonlexHeartRateRange', ['switchState', 'remindValue', 'exerciseSwitchState', 'exerciseHRMin', 'exerciseHRMax', 'exerciseRemindValue'], ['deviceConfig'], 'alerts', 90),
            $entry('wonlexLowPower', 'deviceConfig', 'Limiar de bateria fraca', 'number', ['Battery'], ['deviceConfig'], 'alerts', 10),
            $entry('wonlexFallWarnSwitch', 'deviceConfig', 'Deteção de queda', 'toggle', ['switchState'], ['deviceConfig'], 'alerts', 20),
            $entry('wonlexSOSSwitch', 'deviceConfig', 'SMS SOS', 'toggle', ['switchState'], ['deviceConfig'], 'alerts', 30),
            $entry('wonlexCallInLimitSwitch', 'deviceConfig', 'Restringir chamadas recebidas', 'toggle', ['switchState'], ['deviceConfig'], 'system', 20),
            $entry('alarmClock', 'alarmClock', 'Alarmes', 'json', ['alarmClockList'], ['alarmClock'], 'alerts', 10, 10),
            $entry('familyNumber', 'familyNumber', 'Contactos familiares', 'contacts', ['contacts'], ['familyNumber'], 'contacts', 5, 10),
            $entry('SOSNumber', 'SOSNumber', 'Números SOS', 'list', ['numbers'], ['SOSNumber'], 'contacts', 10, 10),
            $entry('dnMedicationPlan', 'dnMedicationPlan', 'Plano de medicação', 'wonlexMedicationPlans', ['plans'], ['dnMedicationPlan'], 'health', 10),
            $entry('resetCommand', 'reset', 'Reposição de fábrica', 'action', [], ['reset'], 'system', 110, transient: true, confirm: 'Repõe o relógio ao estado de fábrica. Volta a apontar para o servidor do fornecedor e o hub deixa de o comandar até alguém de lá o voltar a configurar.', verb: 'Repor de fábrica'),
            $entry('restartCommand', 'restart', 'Reiniciar dispositivo', 'action', [], ['restart'], 'system', 120, transient: true, confirm: 'O relógio fica sem comunicar enquanto arranca.'),
            $entry('powerOffCommand', 'powerOff', 'Desligar dispositivo', 'action', [], ['powerOff'], 'system', 130, transient: true, confirm: 'O relógio desliga-se e só volta a ligar no botão do próprio aparelho.'),
            $entry('findDeviceCommand', 'find', 'Encontrar dispositivo', 'action', [], ['find'], 'system', 140, transient: true),
            // A Wonlex documenta o `msgNotice` como notificação de sentido único e não define
            // resposta nenhuma do dispositivo para ele.
            $entry('pushMessage', 'msgNotice', 'Enviar mensagem ao relógio', 'pushMessage', ['message'], [], 'system', 145, transient: true),
        ];
    }

    /**
     * Uma das grandezas cuja periodicidade de envio se configura.
     *
     * São dez, com o mesmo comando nativo e a mesma legenda -- é a mesma decisão repetida
     * para grandezas diferentes, e a dashboard agrupa-as por reconhecer essa forma. A frase
     * vive aqui, uma vez, e não dez vezes no ecrã.
     */
    private static function measurementInterval(string $key, string $label, int $order): array
    {
        return ConfigurationDefinition::make(
            $key,
            'deviceMeasuringFrequency',
            $label,
            'number',
            ['interval'],
            ['deviceMeasuringFrequency'],
            'measurements',
            $order,
            help: self::MEASUREMENT_HELP,
        );
    }

    private const MEASUREMENT_HELP =
        'Periodicidade de envio desta medição, em minutos. Use 0 para desativar.';
}
