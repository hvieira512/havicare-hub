<?php

namespace Hub\Domain\Capability\AlarmClock;

use Hub\Domain\Capability\CapabilityContract;
use Hub\Command\DeviceConfigurationCatalog;

/**
 * Capability contract for alarm_clock.
 *
 * Defines the generic shape (items, meta, merge). An empty items array
 * is valid and clears the saved alarms. Per-supplier conversion is delegated
 * to AlarmClockHandler implementations.
 */
final class AlarmClockCapability implements CapabilityContract
{
    use AlarmClockHelpers;

    /** @var array<string, AlarmClockHandler> protocol → handler */
    private array $handlers;

    /** @var array<string, AlarmClockHandler> protocol key → handler */
    private array $handlersByNativeKey;

    /**
     * @param array<string, AlarmClockHandler> $handlers  Keyed by protocol
     */
    public function __construct(array $handlers = [])
    {
        $this->handlers = $handlers;
        $this->handlersByNativeKey = [];
        foreach ($handlers as $handler) {
            $this->handlersByNativeKey[$handler->nativeKey()] = $handler;
        }
    }

    public function key(): string
    {
        return 'alarm_clock';
    }

    public function section(): string
    {
        return 'alarms';
    }

    public function isList(): bool
    {
        return true;
    }

    public function supportsMultipleNativeKeys(): bool
    {
        return false;
    }

    public function supportedProtocols(): array
    {
        return array_keys($this->handlers);
    }

    // ------------------------------------------------------------------
    // Delegation to handlers
    // ------------------------------------------------------------------

    public function toNative(string $protocol, mixed $value): array
    {
        $handler = $this->handlers[$protocol] ?? null;
        if ($handler === null) {
            throw new \InvalidArgumentException("Unsupported protocol {$protocol} for alarm_clock");
        }

        return $handler->toNative($value);
    }

    public function fromNative(string $nativeKey, array $desired): mixed
    {
        $handler = $this->handlersByNativeKey[$nativeKey] ?? null;
        if ($handler !== null) {
            return $handler->fromNative($desired);
        }

        return [];
    }

    public function defaultValue(string $protocol): mixed
    {
        return $this->handlers[$protocol]?->defaultValue() ?? [];
    }

    // ------------------------------------------------------------------
    // Response shape
    // ------------------------------------------------------------------

    public function meta(string $protocol, array $accumulatedMeta = []): array
    {
        $meta = $accumulatedMeta;

        $recurrenceOptions = $meta['mode']['options'] ?? null;
        if (!is_array($recurrenceOptions) || $recurrenceOptions === []) {
            $recurrenceOptions = [
                ['value' => 'once', 'label' => 'Uma vez'],
                ['value' => 'daily', 'label' => 'Todos os dias'],
                ['value' => 'custom', 'label' => 'Personalizado'],
            ];
        } else {
            $recurrenceOptions = array_map(static function (array $option): array {
                $value = strtolower(trim((string)($option['value'] ?? '')));
                if ($value === '1') {
                    $value = 'once';
                } elseif ($value === '2') {
                    $value = 'daily';
                } elseif ($value === '3') {
                    $value = 'custom';
                }

                return [
                    'value' => $value !== '' ? $value : 'once',
                    'label' => (string)($option['label'] ?? ''),
                ];
            }, $recurrenceOptions);
        }
        $meta['recurrence'] = ['options' => $recurrenceOptions];

        return $meta;
    }

    public function merge(mixed $existing, mixed $incoming): mixed
    {
        $existingList = is_array($existing) ? array_values($existing) : [];
        $incomingList = is_array($incoming) ? array_values($incoming) : [];

        return array_values(array_merge($existingList, $incomingList));
    }

    public function responseEntry(string $protocol, string $nativeKey, mixed $value, array $meta): array
    {
        return [
            'value' => $this->fromNative($nativeKey, is_array($value) ? $value : []),
            '_meta' => $this->meta($protocol, $meta),
        ];
    }

    public function resolveConfigKey(string $protocol, string $key): ?string
    {
        if ($key !== 'alarm_clock') {
            return $key;
        }

        $entry = DeviceConfigurationCatalog::configForProtocol($protocol, $key);

        return $entry !== null && trim((string)($entry['key'] ?? '')) !== ''
            ? (string)$entry['key']
            : null;
    }

}
