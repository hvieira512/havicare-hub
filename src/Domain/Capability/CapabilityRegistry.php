<?php

namespace Hub\Domain\Capability;

use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\AlarmClock\AlarmClockCapability;
use Hub\Domain\Capability\AlarmClock\FourPTouch as FourPTouchAlarmClock;
use Hub\Domain\Capability\AlarmClock\Vivistar as VivistarAlarmClock;
use Hub\Domain\Capability\AlarmClock\Wonlex as WonlexAlarmClock;
use Hub\Domain\Capability\Contacts\CallWhitelistCapability;
use Hub\Domain\Capability\Contacts\PhonebookCapability;
use Hub\Domain\Capability\Contacts\SosContactsCapability;
use Hub\Domain\Capability\Contacts\WhitelistEnabledCapability;
use Hub\Domain\Capability\FourPTouch\FourPTouchGenericHandler;
use Hub\Domain\Capability\Medication\MedicationRemindersCapability;

/**
 * Central registry for capability contracts.
 *
 * Complex capabilities (alarm_clock, sos_contacts, call_whitelist, etc.)
 * implement CapabilityContract and are registered here. Simple capabilities
 * (toggles, numbers, phones) are handled generically via
 * DeviceConfigurationCatalog metadata.
 */
final class CapabilityRegistry
{
    use CapabilityHelpers;

    /** @var array<string, CapabilityContract> */
    private array $contracts = [];
    private FourPTouchGenericHandler $fourPTouchGeneric;

    public function __construct()
    {
        $this->fourPTouchGeneric = new FourPTouchGenericHandler();
        $vivistar = new VivistarAlarmClock();
        $fourPTouch = new FourPTouchAlarmClock();
        $this->register(new AlarmClockCapability([
            'vivistar-iw' => $vivistar,
            'wonlex-json' => new WonlexAlarmClock(),
            'four-p-touch' => $fourPTouch,
        ]));

        $this->register(new SosContactsCapability());
        $this->register(new CallWhitelistCapability());
        $this->register(new WhitelistEnabledCapability());
        $this->register(new PhonebookCapability());
        $this->register(new MedicationRemindersCapability());
    }

    public function register(CapabilityContract $capability): void
    {
        $this->contracts[$capability->key()] = $capability;
    }

    public function get(string $genericKey): ?CapabilityContract
    {
        return $this->contracts[$genericKey] ?? null;
    }

    public function has(string $genericKey): bool
    {
        return isset($this->contracts[$genericKey]);
    }

    public function supportsProtocol(string $genericKey, string $protocol): bool
    {
        $contract = $this->contracts[$genericKey] ?? null;
        if ($contract === null) {
            return true;
        }

        return in_array($protocol, $contract->supportedProtocols(), true);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function toNative(string $protocol, string $genericKey, mixed $value): array
    {
        if (isset($this->contracts[$genericKey])) {
            return $this->contracts[$genericKey]->toNative($protocol, $value);
        }

        return $this->genericToNative($protocol, $genericKey, $value);
    }

    public function fromNative(string $genericKey, string $nativeKey, array $desired, string $protocol = ''): mixed
    {
        if (isset($this->contracts[$genericKey])) {
            if ($protocol !== '' && $this->contracts[$genericKey] instanceof AlarmClockCapability) {
                return $this->contracts[$genericKey]->fromNativeForProtocol($protocol, $nativeKey, $desired);
            }
            return $this->contracts[$genericKey]->fromNative($nativeKey, $desired);
        }

        if ($nativeKey !== '' && FourPTouchGenericHandler::nativeKeyToGenericKey($nativeKey) !== null) {
            return $this->fourPTouchGeneric->fromNative($genericKey, $nativeKey, $desired);
        }

        return $desired;
    }

    public function responseEntry(string $protocol, string $genericKey, string $nativeKey, mixed $value, array $meta): array
    {
        if (isset($this->contracts[$genericKey])) {
            return $this->contracts[$genericKey]->responseEntry($protocol, $nativeKey, $value, $meta);
        }

        return [
            'value' => $value,
            '_meta' => $meta,
        ];
    }

    public function defaultValue(string $protocol, string $genericKey): mixed
    {
        if (isset($this->contracts[$genericKey])) {
            return $this->contracts[$genericKey]->defaultValue($protocol);
        }

        return $this->genericDefaultValue($protocol, $genericKey);
    }

    public function merge(string $genericKey, mixed $existing, mixed $incoming): mixed
    {
        if ($existing === null) {
            return $incoming;
        }

        if (isset($this->contracts[$genericKey])) {
            return $this->contracts[$genericKey]->merge($existing, $incoming);
        }

        return self::mergeAssociativeValues($existing, $incoming);
    }

    public function resolveConfigKey(string $protocol, string $key): ?string
    {
        if (isset($this->contracts[$key])) {
            return $this->contracts[$key]->resolveConfigKey($protocol, $key);
        }

        $entry = DeviceConfigurationCatalog::configForProtocol($protocol, $key);

        return $entry !== null ? $key : null;
    }

    // ------------------------------------------------------------------
    // Generic fallback for capabilities without a contract
    // ------------------------------------------------------------------

    /**
     * @return array<string, array<string, mixed>>
     */
    private function genericToNative(string $protocol, string $genericKey, mixed $value): array
    {
        return match ($protocol) {
            'vivistar-iw' => $this->vivistarGenericToNative($genericKey, $value),
            'wonlex-json' => $this->wonlexGenericToNative($genericKey, $value),
            'four-p-touch' => $this->fourPTouchGeneric->toNative($genericKey, $value),
            default => throw new \InvalidArgumentException("Unsupported protocol {$protocol}"),
        };
    }

    private function genericDefaultValue(string $protocol, string $genericKey): mixed
    {
        $entry = $this->findConfigEntryForGenericKey($protocol, $genericKey);
        if ($entry === null) {
            return [];
        }

        return $this->defaultDesiredPayload($entry);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function vivistarGenericToNative(string $genericKey, mixed $value): array
    {
        return match ($genericKey) {
            'working_mode' => ['workingMode' => self::requireObjectValue($value, 'workingMode')],
            'fall_detection' => ['fallDetection' => ['enabled' => self::requireBoolLikeField($value, 'enabled')]],
            'fall_sensitivity' => ['fallSensitivity' => ['sensitivity' => self::requireIntField($value, 'sensitivity')]],
            'push_message' => ['pushMessage' => ['message' => self::requireStringField($value, 'message')]],
            'auto_vitals_interval' => ['autoHealthMeasurement' => self::requireObjectValue($value, 'autoHealthMeasurement')],
            default => throw new \InvalidArgumentException("Unsupported vivistar-iw capability {$genericKey}"),
        };
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function wonlexGenericToNative(string $genericKey, mixed $value): array
    {
        return match ($genericKey) {
            'reset_device' => ['resetCommand' => []],
            'restart_device' => ['restartCommand' => []],
            'power_off' => ['powerOffCommand' => []],
            'find_device' => ['findDeviceCommand' => []],
            'weather_data' => ['weatherData' => self::requireObjectValue($value, 'weatherData')],
            default => [$this->resolveWonlexNativeKey($genericKey) => self::requireObjectValue($value, $genericKey)],
        };
    }

    // ------------------------------------------------------------------
    // Default payload for simple capabilities
    // ------------------------------------------------------------------

    private function defaultDesiredPayload(array $entry): array
    {
        $input = (string)($entry['input'] ?? 'json');
        $field = static fn(int $index = 0): string => (string)($entry['fields'][$index] ?? '');

        return match ($input) {
            'toggle' => [($field(0) ?: 'enabled') => true],
            'number' => [($field(0) ?: 'value') => 0],
            'phone' => [($field(0) ?: 'phone') => ''],
            'text' => [($field(0) ?: 'value') => ''],
            'pushMessage' => ['message' => ''],
            'makeCall' => ['phone' => ''],
            'resetAction', 'requestAction' => [],
            'intervalToggle' => ['enabled' => true, 'intervalMinutes' => 60],
            'intervalHoursToggle' => ['enabled' => true, 'intervalHours' => 2],
            'workingMode' => ['mode' => 1],
            'bloodPressure' => ['systolic' => 120, 'diastolic' => 80],
            'wonlexBloodPressureWarning' => ['switchState' => true, ($field(1) ?: 'reminderValue') => 90],
            'languageTimezone' => ['language' => 0, 'timeZone' => '0'],
            'dualToggle' => ['enabled' => true, 'callCenterOnFall' => false],
            'fallSensitivityLevels' => ['sensitivityLevel' => 5, 'totalLevels' => 8],
            'timeRanges' => ['ranges' => ['08:10-09:30']],
            'timeRange' => ['range' => '21:10-07:30'],
            'wonlexSleepSettings' => [
                'switchState' => true,
                'sleepStartTime' => '220000',
                'sleepEndTime' => '100000',
                'sleepTarget' => 480,
            ],
            'wonlexReminderThreshold' => ['switchState' => true, ($field(1) ?: 'reminderValue') => 90],
            'wonlexHeartRateRange' => [
                'switchState' => true,
                'remindValue' => 120,
                'exerciseSwitchState' => true,
                'exerciseHRMin' => 100,
                'exerciseHRMax' => 140,
                'exerciseRemindValue' => 140,
            ],
            'list' => ['numbers' => array_fill(0, max(1, (int)($entry['limit'] ?? 3)), '')],
            'contacts' => ['contacts' => [['name' => '', 'phone' => '']]],
            'takePills' => [
                'reminderSettings' => [
                    ['time' => '08:00', 'enabled' => true, 'frequency' => 1, 'custom' => ''],
                    ['time' => '09:00', 'enabled' => true, 'frequency' => 1, 'custom' => ''],
                    ['time' => '10:00', 'enabled' => true, 'frequency' => 1, 'custom' => ''],
                ],
                'number' => 1,
                'reminderText' => '',
                'voiceData' => '',
                'voiceMimeType' => 'audio/webm',
            ],
            'soundProfile' => ['mode' => 1],
            default => [],
        };
    }

    // ------------------------------------------------------------------
    // Config entry lookup
    // ------------------------------------------------------------------

    private function findConfigEntryForGenericKey(string $protocol, string $genericKey): ?array
    {
        foreach (DeviceConfigurationCatalog::configsForProtocol($protocol) as $entry) {
            if (CapabilityCatalog::mapConfigurationKey((string)($entry['key'] ?? '')) === $genericKey) {
                return $entry;
            }
        }

        return null;
    }

    private function resolveWonlexNativeKey(string $genericKey): string
    {
        foreach (DeviceConfigurationCatalog::configsForProtocol('wonlex-json') as $entry) {
            $nativeKey = trim((string)($entry['key'] ?? ''));
            if (CapabilityCatalog::mapConfigurationKey($nativeKey) === $genericKey) {
                return $nativeKey;
            }
        }

        throw new \InvalidArgumentException("Unsupported wonlex-json capability {$genericKey}");
    }
}
