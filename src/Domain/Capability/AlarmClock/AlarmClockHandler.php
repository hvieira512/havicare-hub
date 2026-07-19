<?php

namespace Hub\Domain\Capability\AlarmClock;

/**
 * Per-supplier handler for alarm_clock native ↔ public conversion.
 */
interface AlarmClockHandler
{
    /** The native key this handler processes (e.g. 'reminders', 'alarmClock'). */
    public function nativeKey(): string;

    /** Convert generic API value to native key => payload map. */
    public function toNative(mixed $value): array;

    /** Convert native desired payload to list of public items. */
    public function fromNative(array $desired): array;

    /** Default desired payload for this protocol. */
    public function defaultValue(): mixed;
}
