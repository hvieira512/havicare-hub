<?php

namespace Hub\Domain\Capability\AlarmClock;

use Hub\Domain\Capability\CapabilityProtocolHandler;

/**
 * Per-supplier handler for alarm_clock protocol-wire ↔ public conversion.
 */
interface AlarmClockHandler extends CapabilityProtocolHandler
{
    /** The protocol key this handler processes (e.g. 'reminders', 'alarmClock'). */
    public function nativeKey(): string;

    /** Convert generic API value to protocol key => payload map. */
    public function toNative(mixed $value): array;

    /** Convert protocol desired payload to list of public items. */
    public function fromNative(array $desired): array;

    /** Default desired payload for this protocol. */
    public function defaultValue(): mixed;
}
