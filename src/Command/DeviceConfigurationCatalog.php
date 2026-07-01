<?php

namespace Hub\Command;

final class DeviceConfigurationCatalog
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function configsForProtocol(string $protocol): array
    {
        $configs = match ($protocol) {
            'wonlex-json' => self::wonlexConfigs(),
            'vivistar-iw' => self::vivistarConfigs(),
            'four-p-touch' => self::fourPTouchConfigs(),
            default => [],
        };

        usort($configs, static function (array $a, array $b): int {
            $categoryA = (string)($a['category'] ?? '');
            $categoryB = (string)($b['category'] ?? '');
            if ($categoryA !== $categoryB) {
                return strcmp($categoryA, $categoryB);
            }

            $orderA = (int)($a['order'] ?? 0);
            $orderB = (int)($b['order'] ?? 0);
            if ($orderA !== $orderB) {
                return $orderA <=> $orderB;
            }

            return strcmp((string)($a['label'] ?? ''), (string)($b['label'] ?? ''));
        });

        return $configs;
    }

    public static function configForProtocol(string $protocol, string $key): ?array
    {
        foreach (self::configsForProtocol($protocol) as $entry) {
            if (($entry['key'] ?? '') === $key) {
                return $entry;
            }
        }

        return null;
    }

    public static function configForCommand(string $protocol, string $command): ?array
    {
        foreach (self::configsForProtocol($protocol) as $entry) {
            if (($entry['command'] ?? '') === $command) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return array{command: string, payload: array<string, mixed>}
     */
    public static function commandPayload(string $protocol, string $key, array $payload): array
    {
        $entry = self::configForProtocol($protocol, $key);
        if ($entry === null) {
            throw new \InvalidArgumentException("Unsupported {$protocol} configuration {$key}");
        }

        return [
            'command' => (string)$entry['command'],
            'payload' => match ($protocol) {
                'wonlex-json' => self::wonlexPayload($key, $payload),
                'vivistar-iw' => self::vivistarPayload($key, $payload),
                'four-p-touch' => self::fourPTouchPayload($key, $payload),
                default => throw new \InvalidArgumentException("Unsupported protocol {$protocol}"),
            },
        ];
    }

    public static function validate(string $protocol, string $key, array $payload): ?string
    {
        if (self::configForProtocol($protocol, $key) === null) {
            return "Unsupported {$protocol} configuration {$key}";
        }

        try {
            self::commandPayload($protocol, $key, $payload);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    private static function wonlexPayload(string $key, array $payload): array
    {
        if (isset($payload['data']) && is_array($payload['data'])) {
            return $payload['data'];
        }

        return match ($key) {
            'locationInterval' => ['intervalTime' => self::nonNegativeInt($payload['intervalTime'] ?? null, 'intervalTime')],
            'deviceMeasuringFrequency' => ['configs' => self::arrayField($payload['configs'] ?? null, 'configs')],
            'deviceConfig' => ['configs' => self::arrayField($payload['configs'] ?? null, 'configs')],
            'wonlexHeartRateInterval' => self::wonlexMeasurementIntervalPayload('upHeartRate', $payload),
            'wonlexBPInterval' => self::wonlexMeasurementIntervalPayload('upBP', $payload),
            'wonlexBOInterval' => self::wonlexMeasurementIntervalPayload('upBO', $payload),
            'wonlexBodyTemperatureInterval' => self::wonlexMeasurementIntervalPayload('upBodyTemperature', $payload),
            'wonlexStepInterval' => self::wonlexMeasurementIntervalPayload('upStep', $payload),
            'wonlexBreatheInterval' => self::wonlexMeasurementIntervalPayload('upBreathe', $payload),
            'wonlexECGInterval' => self::wonlexMeasurementIntervalPayload('upECG', $payload),
            'wonlexHRVInterval' => self::wonlexMeasurementIntervalPayload('upHRV', $payload),
            'wonlexPPGInterval' => self::wonlexMeasurementIntervalPayload('upPPG', $payload),
            'wonlexRRInterval' => self::wonlexMeasurementIntervalPayload('upRR', $payload),
            'wonlexContinuousBOCheck' => self::wonlexDeviceTogglePayload('ContinuousBOCheck', $payload),
            'wonlexContinuousHRSwitch' => self::wonlexDeviceTogglePayload('ContinuousHRSwitch', $payload),
            'wonlexPPGBPTrend' => self::wonlexDeviceTogglePayload('PPGBPTrend', $payload),
            'wonlexContinuousTempSwitch' => self::wonlexDeviceTogglePayload('ContinuousTempSwitch', $payload),
            'wonlexFallWarnSwitch' => self::wonlexDeviceTogglePayload('FallWarnSwitch', $payload),
            'wonlexSOSSwitch' => self::wonlexDeviceTogglePayload('SOSSwitch', $payload),
            'wonlexCallInLimitSwitch' => self::wonlexDeviceTogglePayload('CallInLimitSwitch', $payload),
            'wonlexStepTarget' => self::wonlexDeviceNumberPayload('StepTarget', 'steps', $payload),
            'wonlexLowPower' => self::wonlexDeviceNumberPayload('LowPower', 'Battery', $payload),
            'wonlexSleepIntervalOrSwitch' => self::wonlexSleepSettingsPayload($payload),
            'wonlexBloodOxygenWarn' => self::wonlexReminderThresholdPayload('bloodOxygenWarn', $payload),
            'wonlexTemperatureExceedRemind' => self::wonlexReminderThresholdPayload('TemperatureExceedRemind', $payload),
            'wonlexTemperatureBelowRemind' => self::wonlexReminderThresholdPayload('TemperatureBelowRemind', $payload),
            'wonlexHeartRateHighRemind' => self::wonlexHeartRateRangePayload('HROvertopRemind', $payload),
            'wonlexHeartRateLowRemind' => self::wonlexHeartRateRangePayload('HeartRateBelowRemind', $payload),
            'wonlexBPEarlyWarning' => self::wonlexBloodPressureWarningPayload($payload),
            'alarmClock' => ['alarmClock' => self::arrayField($payload['alarmClock'] ?? $payload['alarms'] ?? null, 'alarmClock')],
            'SOSNumber' => ['SOSNumber' => self::stringList($payload['numbers'] ?? [], 3, 'numbers')],
            'dnMedicationPlan' => ['plans' => self::arrayField($payload['plans'] ?? $payload['medicationPlan'] ?? null, 'plans')],
            'dnDevBindStatus' => ['bindStatus' => self::boolInt($payload['bindStatus'] ?? $payload['enabled'] ?? null, 'bindStatus')],
            default => throw new \InvalidArgumentException("Unsupported Wonlex configuration {$key}"),
        };
    }

    private static function vivistarPayload(string $key, array $payload): array
    {
        if (isset($payload['fields']) && is_array($payload['fields'])) {
            return ['fields' => array_map(static fn(mixed $value): string => (string)$value, $payload['fields'])];
        }

        $fields = match ($key) {
            'sosContacts' => self::stringList($payload['numbers'] ?? [], 3, 'numbers'),
            'phonebook' => self::vivistarPhonebook($payload['contacts'] ?? []),
            'pushMessage' => [self::utf16Hex(self::requiredString($payload['message'] ?? null, 'message'))],
            'workingMode' => self::vivistarWorkingMode($payload),
            'fallDetection' => [self::boolInt($payload['enabled'] ?? null, 'enabled')],
            'fallSensitivity' => [self::rangeInt($payload['sensitivity'] ?? null, 1, 3, 'sensitivity')],
            'whitelistSwitch' => [self::boolInt($payload['enabled'] ?? null, 'enabled')],
            'reminders' => self::vivistarReminders($payload),
            'autoHealthMeasurement' => [
                self::boolInt($payload['enabled'] ?? null, 'enabled'),
                self::positiveInt($payload['intervalMinutes'] ?? null, 'intervalMinutes'),
            ],
            'bloodPressureCalibration' => self::vivistarBloodPressureCalibration($payload),
            default => throw new \InvalidArgumentException("Unsupported Vivistar configuration {$key}"),
        };

        return ['fields' => array_map(static fn(mixed $value): string => (string)$value, $fields)];
    }

    private static function fourPTouchPayload(string $key, array $payload): array
    {
        if (isset($payload['fields']) && is_array($payload['fields'])) {
            return ['fields' => array_map(static fn(mixed $value): string => trim((string)$value), $payload['fields'])];
        }

        $fields = match ($key) {
            'uploadInterval' => [self::rangeInt($payload['intervalSeconds'] ?? null, 60, 65535, 'intervalSeconds')],
            'sosNumber1', 'sosNumber2', 'sosNumber3', 'monitorNumber' => [self::requiredString($payload['phone'] ?? null, 'phone')],
            'whitelistGroup1', 'whitelistGroup2' => self::stringList($payload['numbers'] ?? [], 5, 'numbers'),
            'devicePassword' => [self::requiredString($payload['password'] ?? null, 'password')],
            'languageTimezone' => [
                self::rangeInt($payload['language'] ?? null, 0, 36, 'language'),
                self::requiredString($payload['timeZone'] ?? null, 'timeZone'),
            ],
            'sosSmsAlerts', 'lowBatterySmsAlerts', 'removeWatchAlarm', 'removeWatchSmsAlerts' => [
                self::boolInt($payload['enabled'] ?? null, 'enabled'),
            ],
            'healthAutoMeasurement' => [
                1,
                self::boolInt($payload['enabled'] ?? null, 'enabled'),
                self::positiveInt($payload['intervalMinutes'] ?? null, 'intervalMinutes'),
            ],
            'walkTime' => self::timeRanges($payload['ranges'] ?? [], 3, 'ranges'),
            'sleepTime' => [self::timeRange($payload['range'] ?? null, 'range')],
            'fallDownAlert' => [
                self::boolInt($payload['enabled'] ?? null, 'enabled'),
                self::boolInt($payload['callCenterOnFall'] ?? null, 'callCenterOnFall'),
            ],
            'fallDownSensitivity' => [self::fallDownSensitivity($payload)],
            'bodyTemperatureInterval' => [
                self::boolInt($payload['enabled'] ?? null, 'enabled'),
                self::rangeInt($payload['intervalHours'] ?? null, 1, 12, 'intervalHours'),
            ],
            default => throw new \InvalidArgumentException("Unsupported 4P Touch configuration {$key}"),
        };

        return ['fields' => array_map(static fn(mixed $value): string => (string)$value, $fields)];
    }

    private static function vivistarWorkingMode(array $payload): array
    {
        $mode = self::rangeInt($payload['mode'] ?? null, 1, 8, 'mode');
        if ($mode !== 8) {
            if (!in_array($mode, [1, 2, 3], true)) {
                throw new \InvalidArgumentException('mode must be 1, 2, 3, or 8');
            }
            return [$mode];
        }

        $interval = self::positiveInt($payload['intervalSeconds'] ?? null, 'intervalSeconds');
        if ($interval < 30) {
            throw new \InvalidArgumentException('intervalSeconds must be at least 30 for mode 8');
        }

        return [8, $interval, self::boolInt($payload['gpsEnabled'] ?? null, 'gpsEnabled')];
    }

    private static function vivistarPhonebook(mixed $contacts): array
    {
        if (!is_array($contacts)) {
            throw new \InvalidArgumentException('contacts must be an array');
        }

        $fields = [];
        foreach (array_slice($contacts, 0, 10) as $contact) {
            if (!is_array($contact)) {
                throw new \InvalidArgumentException('each contact must be an object');
            }
            $phone = trim((string)($contact['phone'] ?? ''));
            if ($phone === '') {
                $fields[] = '';
                continue;
            }
            $name = (string)($contact['name'] ?? '');
            $encodedName = trim((string)($contact['encodedName'] ?? ''));
            $fields[] = ($encodedName !== '' ? $encodedName : self::utf16Hex($name)) . '|' . $phone;
        }

        return array_pad($fields, 10, '');
    }

    private static function vivistarReminders(array $payload): array
    {
        $items = $payload['items'] ?? [];
        if (!is_array($items)) {
            throw new \InvalidArgumentException('items must be an array');
        }

        $entries = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('each reminder item must be an object');
            }
            $time = preg_replace('/[^0-9]/', '', (string)($item['time'] ?? ''));
            if (!is_string($time) || strlen($time) !== 4) {
                throw new \InvalidArgumentException('reminder time must be HH:mm or HHmm');
            }
            $days = preg_replace('/[^0-9]/', '', (string)($item['days'] ?? ''));
            if ($days === '') {
                throw new \InvalidArgumentException('reminder days is required');
            }
            $enabled = self::boolInt($item['enabled'] ?? true, 'enabled');
            $type = self::rangeInt($item['type'] ?? null, 1, 3, 'type');
            $entries[] = "{$time},{$days},{$enabled},{$type}";
        }

        return [
            self::boolInt($payload['masterEnabled'] ?? null, 'masterEnabled'),
            count($entries),
            implode('@', $entries),
        ];
    }

    private static function vivistarBloodPressureCalibration(array $payload): array
    {
        if (isset($payload['values']) && is_array($payload['values'])) {
            return $payload['values'];
        }

        return [
            self::positiveInt($payload['systolic'] ?? null, 'systolic'),
            self::positiveInt($payload['diastolic'] ?? null, 'diastolic'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function wonlexConfigs(): array
    {
        return [
            self::entry('locationInterval', 'locationInterval', 'Intervalo de localização', 'number', ['intervalTime'], ['upDeviceConfig'], 'intervals', 10),
            self::entry('deviceMeasuringFrequency', 'deviceMeasuringFrequency', 'Frequência de medições (JSON)', 'json', ['configs'], ['upDeviceConfig'], 'intervals', 90),
            self::entry('wonlexHeartRateInterval', 'deviceMeasuringFrequency', 'Intervalo de frequência cardíaca', 'number', ['interval'], ['upDeviceConfig'], 'measurements', 10),
            self::entry('wonlexBPInterval', 'deviceMeasuringFrequency', 'Intervalo de tensão arterial', 'number', ['interval'], ['upDeviceConfig'], 'measurements', 20),
            self::entry('wonlexBOInterval', 'deviceMeasuringFrequency', 'Intervalo de oxigénio no sangue', 'number', ['interval'], ['upDeviceConfig'], 'measurements', 30),
            self::entry('wonlexBodyTemperatureInterval', 'deviceMeasuringFrequency', 'Intervalo de temperatura', 'number', ['interval'], ['upDeviceConfig'], 'measurements', 40),
            self::entry('wonlexStepInterval', 'deviceMeasuringFrequency', 'Intervalo de passos', 'number', ['interval'], ['upDeviceConfig'], 'measurements', 50),
            self::entry('wonlexBreatheInterval', 'deviceMeasuringFrequency', 'Intervalo de frequência respiratória', 'number', ['interval'], ['upDeviceConfig'], 'measurements', 60),
            self::entry('wonlexECGInterval', 'deviceMeasuringFrequency', 'Intervalo de ECG', 'number', ['interval'], ['upDeviceConfig'], 'measurements', 70),
            self::entry('wonlexHRVInterval', 'deviceMeasuringFrequency', 'Intervalo de VFC', 'number', ['interval'], ['upDeviceConfig'], 'measurements', 80),
            self::entry('wonlexPPGInterval', 'deviceMeasuringFrequency', 'Intervalo de PPG', 'number', ['interval'], ['upDeviceConfig'], 'measurements', 90),
            self::entry('wonlexRRInterval', 'deviceMeasuringFrequency', 'Intervalo de RR', 'number', ['interval'], ['upDeviceConfig'], 'measurements', 100),
            self::entry('deviceConfig', 'deviceConfig', 'Configuração do dispositivo (JSON)', 'json', ['configs'], ['upDeviceConfig'], 'system', 90),
            self::entry('wonlexStepTarget', 'deviceConfig', 'Meta de passos', 'number', ['steps'], ['upDeviceConfig'], 'health', 10),
            self::entry('wonlexContinuousBOCheck', 'deviceConfig', 'Oxigénio contínuo em repouso', 'toggle', ['switchState'], ['upDeviceConfig'], 'health', 20),
            self::entry('wonlexContinuousHRSwitch', 'deviceConfig', 'Frequência cardíaca contínua', 'toggle', ['switchState'], ['upDeviceConfig'], 'health', 30),
            self::entry('wonlexPPGBPTrend', 'deviceConfig', 'Tendência PPG da pressão arterial', 'toggle', ['switchState'], ['upDeviceConfig'], 'health', 40),
            self::entry('wonlexContinuousTempSwitch', 'deviceConfig', 'Temperatura automática', 'toggle', ['switchState'], ['upDeviceConfig'], 'health', 50),
            self::entry('wonlexSleepIntervalOrSwitch', 'deviceConfig', 'Definições de sono', 'wonlexSleepSettings', ['switchState', 'sleepStartTime', 'sleepEndTime', 'sleepTarget'], ['upDeviceConfig'], 'health', 60),
            self::entry('wonlexBloodOxygenWarn', 'deviceConfig', 'Alerta de oxigénio baixo', 'wonlexReminderThreshold', ['switchState', 'reminderValue'], ['upDeviceConfig'], 'alerts', 40),
            self::entry('wonlexTemperatureExceedRemind', 'deviceConfig', 'Alerta de temperatura alta', 'wonlexReminderThreshold', ['switchState', 'RemindValue'], ['upDeviceConfig'], 'alerts', 50),
            self::entry('wonlexTemperatureBelowRemind', 'deviceConfig', 'Alerta de temperatura baixa', 'wonlexReminderThreshold', ['switchState', 'RemindValue'], ['upDeviceConfig'], 'alerts', 60),
            self::entry('wonlexBPEarlyWarning', 'deviceConfig', 'Alerta de tensão arterial', 'wonlexBloodPressureWarning', ['switchState', 'hpWarn', 'LPWarn'], ['upDeviceConfig'], 'alerts', 70),
            self::entry('wonlexHeartRateHighRemind', 'deviceConfig', 'Alerta de frequência cardíaca alta', 'wonlexHeartRateRange', ['switchState', 'remindValue', 'exerciseSwitchState', 'exerciseHRMin', 'exerciseHRMax', 'exerciseRemindValue'], ['upDeviceConfig'], 'alerts', 80),
            self::entry('wonlexHeartRateLowRemind', 'deviceConfig', 'Alerta de frequência cardíaca baixa', 'wonlexHeartRateRange', ['switchState', 'remindValue', 'exerciseSwitchState', 'exerciseHRMin', 'exerciseHRMax', 'exerciseRemindValue'], ['upDeviceConfig'], 'alerts', 90),
            self::entry('wonlexLowPower', 'deviceConfig', 'Limiar de bateria fraca', 'number', ['Battery'], ['upDeviceConfig'], 'alerts', 10),
            self::entry('wonlexFallWarnSwitch', 'deviceConfig', 'Deteção de queda', 'toggle', ['switchState'], ['upDeviceConfig'], 'alerts', 20),
            self::entry('wonlexSOSSwitch', 'deviceConfig', 'SMS SOS', 'toggle', ['switchState'], ['upDeviceConfig'], 'alerts', 30),
            self::entry('wonlexCallInLimitSwitch', 'deviceConfig', 'Restringir chamadas recebidas', 'toggle', ['switchState'], ['upDeviceConfig'], 'system', 20),
            self::entry('alarmClock', 'alarmClock', 'Alarmes', 'json', ['alarmClock'], ['upDeviceConfig'], 'alerts', 10),
            self::entry('SOSNumber', 'SOSNumber', 'Números SOS', 'list', ['numbers'], ['upDeviceConfig'], 'contacts', 10, 3),
            self::entry('dnMedicationPlan', 'dnMedicationPlan', 'Plano de medicação', 'json', ['plans'], ['upDeviceConfig'], 'health', 10),
            self::entry('dnDevBindStatus', 'dnDevBindStatus', 'Estado de vinculação', 'toggle', ['bindStatus'], [], 'system', 20),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function vivistarConfigs(): array
    {
        return [
            self::entry('sosContacts', 'BP12', 'Contactos SOS', 'list', ['numbers'], ['AP12'], 'contacts', 10, 3),
            self::entry('phonebook', 'BP14', 'Lista telefónica', 'contacts', ['contacts'], ['AP14'], 'contacts', 20, 10),
            self::entry('pushMessage', 'BP40', 'Enviar mensagem ao relógio', 'pushMessage', ['message'], ['AP40'], 'system', 5) + ['transient' => true],
            self::entry('workingMode', 'BP33', 'Modo de trabalho', 'workingMode', ['mode'], ['AP33'], 'system', 10, null, [
                'mode' => [
                    ['value' => 1, 'label' => 'Normal'],
                    ['value' => 2, 'label' => 'Poupança'],
                    ['value' => 3, 'label' => 'Emergência'],
                    ['value' => 8, 'label' => 'Personalizado', 'fields' => [
                        'intervalSeconds' => ['type' => 'integer', 'min' => 30],
                        'gpsEnabled' => ['type' => 'boolean'],
                    ]],
                ],
            ]),
            self::entry('fallDetection', 'BP76', 'Deteção de queda', 'toggle', ['enabled'], ['AP76'], 'alerts', 10),
            self::entry('fallSensitivity', 'BP77', 'Sensibilidade de queda', 'fallSensitivity', ['sensitivity'], ['AP77'], 'alerts', 20, null, [
                'sensitivity' => [
                    ['value' => 1, 'label' => 'Baixa'],
                    ['value' => 2, 'label' => 'Normal'],
                    ['value' => 3, 'label' => 'Alta'],
                ],
            ]),
            self::entry('whitelistSwitch', 'BP84', 'Filtro da lista telefónica', 'toggle', ['enabled'], ['AP84'], 'system', 20),
            self::entry('reminders', 'BP85', 'Lembretes / Alarmes', 'reminders', ['masterEnabled', 'items'], ['AP85'], 'alerts', 30, null, [
                'days' => [
                    ['value' => 1, 'label' => 'Seg'],
                    ['value' => 2, 'label' => 'Ter'],
                    ['value' => 3, 'label' => 'Qua'],
                    ['value' => 4, 'label' => 'Qui'],
                    ['value' => 5, 'label' => 'Sex'],
                    ['value' => 6, 'label' => 'Sab'],
                    ['value' => 7, 'label' => 'Dom'],
                ],
                'type' => [
                    ['value' => 1, 'label' => 'Medicação'],
                    ['value' => 2, 'label' => 'Água'],
                    ['value' => 3, 'label' => 'Sedentarismo'],
                ],
            ]),
            self::entry('autoHealthMeasurement', 'BP86', 'Medição automática de saúde', 'intervalToggle', ['enabled', 'intervalMinutes'], ['AP86'], 'health', 10),
            self::entry('bloodPressureCalibration', 'BPJZ', 'Calibração da tensão arterial', 'bloodPressure', ['systolic', 'diastolic'], ['APJZ'], 'health', 20),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function fourPTouchConfigs(): array
    {
        return [
            self::entry('uploadInterval', 'UPLOAD', 'Intervalo de localização', 'number', ['intervalSeconds'], ['UPLOAD'], 'intervals', 10),
            self::entry('sosNumber1', 'SOS1', 'SOS 1', 'phone', ['phone'], ['SOS1'], 'contacts', 10),
            self::entry('sosNumber2', 'SOS2', 'SOS 2', 'phone', ['phone'], ['SOS2'], 'contacts', 20),
            self::entry('sosNumber3', 'SOS3', 'SOS 3', 'phone', ['phone'], ['SOS3'], 'contacts', 30),
            self::entry('whitelistGroup1', 'WHITELIST1', 'Lista branca 1-5', 'list', ['numbers'], ['WHITELIST1'], 'contacts', 40, 5),
            self::entry('whitelistGroup2', 'WHITELIST2', 'Lista branca 6-10', 'list', ['numbers'], ['WHITELIST2'], 'contacts', 50, 5),
            self::entry('monitorNumber', 'MONITOR', 'Número de monitorização', 'phone', ['phone'], ['MONITOR'], 'contacts', 60),
            self::entry('devicePassword', 'PW', 'Palavra-passe do dispositivo', 'text', ['password'], ['PW'], 'system', 10),
            self::entry('languageTimezone', 'LZ', 'Idioma e fuso horário', 'languageTimezone', ['language', 'timeZone'], ['LZ'], 'system', 20),
            self::entry('sosSmsAlerts', 'SOSSMS', 'SMS em alarme SOS', 'toggle', ['enabled'], ['SOSSMS'], 'alerts', 10),
            self::entry('lowBatterySmsAlerts', 'LOWBAT', 'SMS em bateria fraca', 'toggle', ['enabled'], ['LOWBAT'], 'alerts', 20),
            self::entry('removeWatchAlarm', 'REMOVE', 'Alarme ao retirar relógio', 'toggle', ['enabled'], ['REMOVE'], 'alerts', 30),
            self::entry('removeWatchSmsAlerts', 'REMOVESMS', 'SMS ao retirar relógio', 'toggle', ['enabled'], ['REMOVESMS'], 'alerts', 40),
            self::entry('fallDownAlert', 'FALLDOWN', 'Alerta de queda', 'dualToggle', ['enabled', 'callCenterOnFall'], ['FALLDOWN'], 'alerts', 50),
            self::entry('fallDownSensitivity', 'LSSET', 'Sensibilidade de queda', 'fallSensitivityLevels', ['sensitivityLevel', 'totalLevels'], ['LSSET'], 'alerts', 60),
            self::entry('healthAutoMeasurement', 'HEALTHAUTOSET', 'Medição automática de saúde', 'intervalToggle', ['enabled', 'intervalMinutes'], ['HEALTHAUTOSET'], 'health', 10),
            self::entry('walkTime', 'WALKTIME', 'Janela de pedómetro', 'timeRanges', ['ranges'], ['WALKTIME'], 'health', 20, 3),
            self::entry('sleepTime', 'SLEEPTIME', 'Deteção de sono e rotação', 'timeRange', ['range'], ['SLEEPTIME'], 'health', 30),
            self::entry('bodyTemperatureInterval', 'bodytemp', 'Temperatura periódica', 'intervalHoursToggle', ['enabled', 'intervalHours'], ['bodytemp'], 'health', 40),
        ];
    }

    private static function entry(
        string $key,
        string $command,
        string $label,
        string $input,
        array $fields,
        array $expectedReplyTypes = [],
        string $category = 'general',
        int $order = 0,
        ?int $limit = null,
        ?array $options = null
    ): array {
        $entry = [
            'key' => $key,
            'command' => $command,
            'label' => $label,
            'kind' => 'config',
            'risk' => 'normal',
            'input' => $input,
            'fields' => $fields,
            'expectedReplyTypes' => $expectedReplyTypes,
            'category' => $category,
            'order' => $order,
        ];

        if ($limit !== null) {
            $entry['limit'] = $limit;
        }

        if ($options !== null) {
            $entry['options'] = $options;
        }

        return $entry;
    }

    private static function arrayField(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException("{$field} must be an object or array");
        }
        return $value;
    }

    private static function wonlexMeasurementIntervalPayload(string $metric, array $payload): array
    {
        return [
            'configs' => [
                $metric => [
                    'interval' => (string)self::positiveInt($payload['interval'] ?? null, 'interval'),
                ],
            ],
        ];
    }

    private static function wonlexDeviceTogglePayload(string $configName, array $payload): array
    {
        return [
            'configs' => [
                $configName => [
                    'switchState' => self::boolInt($payload['switchState'] ?? $payload['enabled'] ?? null, 'switchState'),
                ],
            ],
        ];
    }

    private static function wonlexDeviceNumberPayload(string $configName, string $field, array $payload): array
    {
        return [
            'configs' => [
                $configName => [
                    $field => self::nonNegativeInt($payload[$field] ?? $payload['value'] ?? null, $field),
                ],
            ],
        ];
    }

    private static function wonlexSleepSettingsPayload(array $payload): array
    {
        return [
            'configs' => [
                'SleepIntervalOrSwitch' => [
                    'switchState' => self::boolInt($payload['switchState'] ?? null, 'switchState'),
                    'sleepStartTime' => self::requiredString($payload['sleepStartTime'] ?? null, 'sleepStartTime'),
                    'sleepEndTime' => self::requiredString($payload['sleepEndTime'] ?? null, 'sleepEndTime'),
                    'sleepTarget' => self::nonNegativeInt($payload['sleepTarget'] ?? null, 'sleepTarget'),
                ],
            ],
        ];
    }

    private static function wonlexReminderThresholdPayload(string $configName, array $payload): array
    {
        $valueKey = array_key_exists('RemindValue', $payload) ? 'RemindValue' : 'reminderValue';

        return [
            'configs' => [
                $configName => [
                    'switchState' => self::boolInt($payload['switchState'] ?? null, 'switchState'),
                    $valueKey => self::nonNegativeInt($payload[$valueKey] ?? null, $valueKey),
                ],
            ],
        ];
    }

    private static function wonlexHeartRateRangePayload(string $configName, array $payload): array
    {
        return [
            'configs' => [
                $configName => [
                    'switchState' => self::boolInt($payload['switchState'] ?? null, 'switchState'),
                    'remindValue' => self::nonNegativeInt($payload['remindValue'] ?? null, 'remindValue'),
                    'exerciseSwitchState' => self::boolInt($payload['exerciseSwitchState'] ?? null, 'exerciseSwitchState'),
                    'exerciseHRMin' => self::nonNegativeInt($payload['exerciseHRMin'] ?? null, 'exerciseHRMin'),
                    'exerciseHRMax' => self::nonNegativeInt($payload['exerciseHRMax'] ?? null, 'exerciseHRMax'),
                    'exerciseRemindValue' => self::nonNegativeInt($payload['exerciseRemindValue'] ?? null, 'exerciseRemindValue'),
                ],
            ],
        ];
    }

    private static function wonlexBloodPressureWarningPayload(array $payload): array
    {
        return [
            'configs' => [
                'BPEarlyWarning' => [
                    'switchState' => self::boolInt($payload['switchState'] ?? null, 'switchState'),
                    'hpWarn' => self::nonNegativeInt($payload['hpWarn'] ?? null, 'hpWarn'),
                    'LPWarn' => self::nonNegativeInt($payload['LPWarn'] ?? null, 'LPWarn'),
                ],
            ],
        ];
    }

    private static function stringList(mixed $value, int $max, string $field): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException("{$field} must be an array");
        }
        return array_pad(array_map(static fn(mixed $item): string => trim((string)$item), array_slice($value, 0, $max)), $max, '');
    }

    private static function requiredString(mixed $value, string $field): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            throw new \InvalidArgumentException("{$field} is required");
        }

        return $value;
    }

    private static function boolInt(mixed $value, string $field): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if ($value === 0 || $value === 1 || $value === '0' || $value === '1') {
            return (int)$value;
        }
        throw new \InvalidArgumentException("{$field} must be boolean or 0/1");
    }

    private static function nonNegativeInt(mixed $value, string $field): int
    {
        if (!is_numeric((string)$value) || (int)$value < 0) {
            throw new \InvalidArgumentException("{$field} must be a non-negative integer");
        }
        return (int)$value;
    }

    private static function positiveInt(mixed $value, string $field): int
    {
        if (!is_numeric((string)$value) || (int)$value <= 0) {
            throw new \InvalidArgumentException("{$field} must be a positive integer");
        }
        return (int)$value;
    }

    private static function rangeInt(mixed $value, int $min, int $max, string $field): int
    {
        $value = self::positiveInt($value, $field);
        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException("{$field} must be between {$min} and {$max}");
        }
        return $value;
    }

    private static function utf16Hex(string $value): string
    {
        $encoded = iconv('UTF-8', 'UTF-16BE//IGNORE', $value);
        return strtoupper(bin2hex($encoded !== false ? $encoded : $value));
    }

    /**
     * @return list<string>
     */
    private static function timeRanges(mixed $value, int $max, string $field): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException("{$field} must be an array");
        }

        $ranges = [];
        foreach (array_slice($value, 0, $max) as $item) {
            if (trim((string)$item) === '') {
                continue;
            }
            $ranges[] = self::timeRange($item, $field);
        }

        if ($ranges === []) {
            throw new \InvalidArgumentException("{$field} must contain at least one time range");
        }

        return $ranges;
    }

    private static function timeRange(mixed $value, string $field): string
    {
        $value = trim((string)$value);
        if (!preg_match('/^\d{1,2}:\d{2}-\d{1,2}:\d{2}$/', $value)) {
            throw new \InvalidArgumentException("{$field} must use HH:MM-HH:MM");
        }

        [$start, $end] = explode('-', $value, 2);
        self::validateClockTime($start, $field);
        self::validateClockTime($end, $field);

        return $value;
    }

    private static function validateClockTime(string $value, string $field): void
    {
        [$hour, $minute] = array_map('intval', explode(':', $value, 2));
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            throw new \InvalidArgumentException("{$field} must use valid 24h times");
        }
    }

    private static function fallDownSensitivity(array $payload): string
    {
        $level = self::positiveInt($payload['sensitivityLevel'] ?? null, 'sensitivityLevel');
        $totalLevels = self::rangeInt($payload['totalLevels'] ?? null, 6, 8, 'totalLevels');
        if (!in_array($totalLevels, [6, 8], true)) {
            throw new \InvalidArgumentException('totalLevels must be 6 or 8');
        }
        if ($level > $totalLevels) {
            throw new \InvalidArgumentException('sensitivityLevel must not exceed totalLevels');
        }

        return "{$level}+{$totalLevels}";
    }
}
