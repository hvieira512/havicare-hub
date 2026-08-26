<?php

namespace Hub\Domain\Capability;

use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\AlarmClock\AlarmClockCapability;
use Hub\Domain\Capability\AlarmClock\FourPTouch as FourPTouchAlarmClock;
use Hub\Domain\Capability\AlarmClock\Vivistar as VivistarAlarmClock;
use Hub\Domain\Capability\AlarmClock\Wonlex as WonlexAlarmClock;
use Hub\Domain\Capability\Alarms\SosSmsAlertCapability;
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
        $this->register(new SosSmsAlertCapability());
        $this->register(new MedicationRemindersCapability());
        $this->register(new DiaperSensitivityCapability());
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

    /**
     * Se a alteração tem de viajar para o dispositivo, ou se o hub a aplica sozinho.
     *
     * Por omissão viaja: uma configuração é um downlink à espera de acontecer, e uma
     * capacidade só sai dessa regra dizendo-o com o `HubAppliedCapability`.
     */
    public function travelsToDevice(string $genericKey): bool
    {
        return !(($this->contracts[$genericKey] ?? null) instanceof HubAppliedCapability);
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

    public function sanitizeInput(string $protocol, string $genericKey, mixed $value): mixed
    {
        $contract = $this->contracts[$genericKey] ?? null;

        return $contract instanceof CapabilityInputSanitizer
            ? $contract->sanitizeInput($protocol, $value)
            : $value;
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

        return $protocol === 'wonlex-json'
            ? $this->wonlexFromNative($desired)
            : $desired;
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
            'push_message' => ['pushMessage' => ['message' => self::requireStringField($value, 'message')]],
            default => [
                $this->resolveWonlexNativeKey($genericKey) => $this->wonlexToNative(
                    self::requireObjectValue($value, $genericKey)
                ),
            ],
        };
    }

    /**
     * Keep Wonlex transport names out of the generic API contract.
     *
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function wonlexFromNative(array $value): array
    {
        if (array_key_exists('switchState', $value)) {
            $value['enabled'] = self::wonlexBool($value['switchState'], 'switchState');
            unset($value['switchState']);
        }
        if (array_key_exists('exerciseSwitchState', $value)) {
            $value['exerciseEnabled'] = self::wonlexBool($value['exerciseSwitchState'], 'exerciseSwitchState');
            unset($value['exerciseSwitchState']);
        }

        return $value;
    }

    /**
     * Accept the normalized API names while retaining compatibility with
     * legacy payloads that already contain Wonlex-native fields.
     *
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function wonlexToNative(array $value): array
    {
        if (array_key_exists('enabled', $value) && !array_key_exists('switchState', $value)) {
            $value['switchState'] = self::wonlexBool($value['enabled'], 'enabled');
        }
        unset($value['enabled']);

        if (array_key_exists('exerciseEnabled', $value) && !array_key_exists('exerciseSwitchState', $value)) {
            $value['exerciseSwitchState'] = self::wonlexBool($value['exerciseEnabled'], 'exerciseEnabled');
        }
        unset($value['exerciseEnabled']);

        return $value;
    }

    private static function wonlexBool(mixed $value, string $field): bool
    {
        $normalized = self::requireBoolLikeValue($value, $field);

        return in_array($normalized, [true, 1, '1'], true);
    }

    // ------------------------------------------------------------------
    // Default payload for simple capabilities
    // ------------------------------------------------------------------

    private function defaultDesiredPayload(array $entry): array
    {
        return ConfigurationInputDefaults::forEntry($entry);
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
