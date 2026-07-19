<?php

namespace Hub\Command\Configuration\Definition;

final class WonlexConfigurationDefinitions
{
    public static function all(): array
    {
        $entry = ConfigurationDefinition::make(...);

        return [
            $entry('locationInterval', 'locationInterval', 'Intervalo de localização', 'number', ['intervalTime'], ['upDeviceConfig'], 'intervals', 10),
            $entry('deviceMeasuringFrequency', 'deviceMeasuringFrequency', 'Frequência de medições (JSON)', 'json', ['configs'], ['upDeviceConfig'], 'intervals', 90),
            $entry('wonlexHeartRateInterval', 'deviceMeasuringFrequency', 'Intervalo de frequência cardíaca', 'number', ['interval'], ['upDeviceConfig'], 'measurements', 10),
            $entry('wonlexBPInterval', 'deviceMeasuringFrequency', 'Intervalo de tensão arterial', 'number', ['interval'], ['upDeviceConfig'], 'measurements', 20),
            $entry('wonlexBOInterval', 'deviceMeasuringFrequency', 'Intervalo de oxigénio no sangue', 'number', ['interval'], ['upDeviceConfig'], 'measurements', 30),
            $entry('wonlexBodyTemperatureInterval', 'deviceMeasuringFrequency', 'Intervalo de temperatura', 'number', ['interval'], ['upDeviceConfig'], 'measurements', 40),
            $entry('wonlexStepInterval', 'deviceMeasuringFrequency', 'Intervalo de passos', 'number', ['interval'], ['upDeviceConfig'], 'measurements', 50),
            $entry('wonlexBreatheInterval', 'deviceMeasuringFrequency', 'Intervalo de frequência respiratória', 'number', ['interval'], ['upDeviceConfig'], 'measurements', 60),
            $entry('wonlexECGInterval', 'deviceMeasuringFrequency', 'Intervalo de ECG', 'number', ['interval'], ['upDeviceConfig'], 'measurements', 70),
            $entry('wonlexHRVInterval', 'deviceMeasuringFrequency', 'Intervalo de VFC', 'number', ['interval'], ['upDeviceConfig'], 'measurements', 80),
            $entry('wonlexPPGInterval', 'deviceMeasuringFrequency', 'Intervalo de PPG', 'number', ['interval'], ['upDeviceConfig'], 'measurements', 90),
            $entry('wonlexRRInterval', 'deviceMeasuringFrequency', 'Intervalo de RR', 'number', ['interval'], ['upDeviceConfig'], 'measurements', 100),
            $entry('deviceConfig', 'deviceConfig', 'Configuração do dispositivo (JSON)', 'json', ['configs'], ['upDeviceConfig'], 'system', 90),
            $entry('wonlexStepTarget', 'deviceConfig', 'Meta de passos', 'number', ['steps'], ['upDeviceConfig'], 'health', 10),
            $entry('wonlexContinuousBOCheck', 'deviceConfig', 'Oxigénio contínuo em repouso', 'toggle', ['switchState'], ['upDeviceConfig'], 'health', 20),
            $entry('wonlexContinuousHRSwitch', 'deviceConfig', 'Frequência cardíaca contínua', 'toggle', ['switchState'], ['upDeviceConfig'], 'health', 30),
            $entry('wonlexPPGBPTrend', 'deviceConfig', 'Tendência PPG da pressão arterial', 'toggle', ['switchState'], ['upDeviceConfig'], 'health', 40),
            $entry('wonlexContinuousTempSwitch', 'deviceConfig', 'Temperatura automática', 'toggle', ['switchState'], ['upDeviceConfig'], 'health', 50),
            $entry('wonlexSleepIntervalOrSwitch', 'deviceConfig', 'Definições de sono', 'wonlexSleepSettings', ['switchState', 'sleepStartTime', 'sleepEndTime', 'sleepTarget'], ['upDeviceConfig'], 'health', 60),
            $entry('wonlexBloodOxygenWarn', 'deviceConfig', 'Alerta de oxigénio baixo', 'wonlexReminderThreshold', ['switchState', 'reminderValue'], ['upDeviceConfig'], 'alerts', 40),
            $entry('wonlexTemperatureExceedRemind', 'deviceConfig', 'Alerta de temperatura alta', 'wonlexReminderThreshold', ['switchState', 'RemindValue'], ['upDeviceConfig'], 'alerts', 50),
            $entry('wonlexTemperatureBelowRemind', 'deviceConfig', 'Alerta de temperatura baixa', 'wonlexReminderThreshold', ['switchState', 'RemindValue'], ['upDeviceConfig'], 'alerts', 60),
            $entry('wonlexBPEarlyWarning', 'deviceConfig', 'Alerta de tensão arterial', 'wonlexBloodPressureWarning', ['switchState', 'hpWarn', 'LPWarn'], ['upDeviceConfig'], 'alerts', 70),
            $entry('wonlexHeartRateHighRemind', 'deviceConfig', 'Alerta de frequência cardíaca alta', 'wonlexHeartRateRange', ['switchState', 'remindValue', 'exerciseSwitchState', 'exerciseHRMin', 'exerciseHRMax', 'exerciseRemindValue'], ['upDeviceConfig'], 'alerts', 80),
            $entry('wonlexHeartRateLowRemind', 'deviceConfig', 'Alerta de frequência cardíaca baixa', 'wonlexHeartRateRange', ['switchState', 'remindValue', 'exerciseSwitchState', 'exerciseHRMin', 'exerciseHRMax', 'exerciseRemindValue'], ['upDeviceConfig'], 'alerts', 90),
            $entry('wonlexLowPower', 'deviceConfig', 'Limiar de bateria fraca', 'number', ['Battery'], ['upDeviceConfig'], 'alerts', 10),
            $entry('wonlexFallWarnSwitch', 'deviceConfig', 'Deteção de queda', 'toggle', ['switchState'], ['upDeviceConfig'], 'alerts', 20),
            $entry('wonlexSOSSwitch', 'deviceConfig', 'SMS SOS', 'toggle', ['switchState'], ['upDeviceConfig'], 'alerts', 30),
            $entry('wonlexCallInLimitSwitch', 'deviceConfig', 'Restringir chamadas recebidas', 'toggle', ['switchState'], ['upDeviceConfig'], 'system', 20),
            $entry('alarmClock', 'alarmClock', 'Alarmes', 'json', ['alarmClock'], ['upDeviceConfig'], 'alerts', 10),
            $entry('SOSNumber', 'SOSNumber', 'Números SOS', 'list', ['numbers'], ['upDeviceConfig'], 'contacts', 10, 3),
            $entry('dnMedicationPlan', 'dnMedicationPlan', 'Plano de medicação', 'json', ['plans'], ['upDeviceConfig'], 'health', 10),
            $entry('dnDevBindStatus', 'dnDevBindStatus', 'Estado de vinculação', 'toggle', ['bindStatus'], [], 'system', 20),
        ];
    }
}
