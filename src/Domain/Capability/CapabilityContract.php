<?php

namespace Hub\Domain\Capability;

/**
 * Defines the contract for a generic capability.
 *
 * Every capability (alarm_clock, phonebook, fall_detection, etc.) implements
 * this interface so that DeviceService and DeviceConfigurationCatalog can
 * delegate to a single object instead of scattering logic across match arms.
 */
interface CapabilityContract
{
    /**
     * The generic key used in API requests and responses (e.g. 'alarm_clock').
     */
    public function key(): string;

    /**
     * The section this capability belongs to (e.g. 'alarms', 'contacts').
     */
    public function section(): string;

    /**
     * Whether this capability uses a list-based response shape (items)
     * instead of the default value-based shape.
     */
    public function isList(): bool;

    /**
     * Protocols that this capability supports.
     *
     * @return list<string>
     */
    public function supportedProtocols(): array;

    /**
     * Convert a generic API input value to the native key => payload map
     * that DeviceConfigurationCatalog can consume.
     *
     * @return array<string, array<string, mixed>>
     */
    public function toNative(string $protocol, mixed $value): array;

    /**
     * Convert a native desired payload stored in device_configurations
     * back to the public generic form for the API response.
     */
    public function fromNative(string $nativeKey, array $desired): mixed;

    /**
     * Default value returned when no configuration row exists for the device.
     */
    public function defaultValue(string $protocol): mixed;

    /**
     * Build the _meta array for the API response.
     *
     * @param array<string, mixed> $accumulatedMeta  Meta accumulated from config rows
     */
    public function meta(string $protocol, array $accumulatedMeta = []): array;

    /**
     * Merge existing and incoming values when multiple native keys map
     * to the same generic key.
     */
    public function merge(mixed $existing, mixed $incoming): mixed;

    /**
     * Build the full capability entry for the API response.
     */
    public function responseEntry(string $protocol, string $nativeKey, mixed $value, array $meta): array;

    /**
     * Resolve the native key for a given protocol.
     */
    public function nativeKeyForProtocol(string $protocol): ?string;

    /**
     * Resolve the public key alias used in API configurations.
     * For alarm_clock: generic 'alarm_clock' → native 'reminders' or 'alarmClock'.
     */
    public function resolveConfigKey(string $protocol, string $key): ?string;
}
