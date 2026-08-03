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
            $entry('wonlexHeartRateInterval', 'deviceMeasuringFrequency', 'Intervalo de frequência cardíaca', 'number', ['interval'], ['deviceMeasuringFrequency'], 'measurements', 10),
            $entry('wonlexBPInterval', 'deviceMeasuringFrequency', 'Intervalo de tensão arterial', 'number', ['interval'], ['deviceMeasuringFrequency'], 'measurements', 20),
            $entry('wonlexBOInterval', 'deviceMeasuringFrequency', 'Intervalo de oxigénio no sangue', 'number', ['interval'], ['deviceMeasuringFrequency'], 'measurements', 30),
            $entry('wonlexBodyTemperatureInterval', 'deviceMeasuringFrequency', 'Intervalo de temperatura', 'number', ['interval'], ['deviceMeasuringFrequency'], 'measurements', 40),
            $entry('wonlexStepInterval', 'deviceMeasuringFrequency', 'Intervalo de passos', 'number', ['interval'], ['deviceMeasuringFrequency'], 'measurements', 50),
            $entry('wonlexBreatheInterval', 'deviceMeasuringFrequency', 'Intervalo de frequência respiratória', 'number', ['interval'], ['deviceMeasuringFrequency'], 'measurements', 60),
            $entry('wonlexECGInterval', 'deviceMeasuringFrequency', 'Intervalo de ECG', 'number', ['interval'], ['deviceMeasuringFrequency'], 'measurements', 70),
            $entry('wonlexHRVInterval', 'deviceMeasuringFrequency', 'Intervalo de VFC', 'number', ['interval'], ['deviceMeasuringFrequency'], 'measurements', 80),
            $entry('wonlexPPGInterval', 'deviceMeasuringFrequency', 'Intervalo de PPG', 'number', ['interval'], ['deviceMeasuringFrequency'], 'measurements', 90),
            $entry('wonlexRRInterval', 'deviceMeasuringFrequency', 'Intervalo de RR', 'number', ['interval'], ['deviceMeasuringFrequency'], 'measurements', 100),
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
            $entry('dnDevBindStatus', 'dnDevBindStatus', 'Estado de vinculação', 'toggle', ['status'], ['dnDevBindStatus'], 'system', 20),
            $entry('resetCommand', 'reset', 'Reposição de fábrica', 'resetAction', [], ['reset'], 'system', 110, null, null, true),
            $entry('restartCommand', 'restart', 'Reiniciar dispositivo', 'resetAction', [], ['restart'], 'system', 120, null, null, true),
            $entry('powerOffCommand', 'powerOff', 'Desligar dispositivo', 'resetAction', [], ['powerOff'], 'system', 130, null, null, true),
            $entry('findDeviceCommand', 'find', 'Encontrar dispositivo', 'requestAction', [], ['find'], 'system', 140, null, null, true),
            // Wonlex documents msgNotice as a one-way notification and does not
            // define a device reply for it.
            $entry('pushMessage', 'msgNotice', 'Enviar mensagem ao relógio', 'pushMessage', ['message'], [], 'system', 145, null, null, true),
            $entry('weatherData', 'dnWeather', 'Dados meteorológicos', 'wonlexWeather', ['weather'], ['dnWeather'], 'system', 150),
        ];
    }
}
