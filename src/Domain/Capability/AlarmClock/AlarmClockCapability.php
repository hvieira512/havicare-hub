<?php

namespace Hub\Domain\Capability\AlarmClock;

use Hub\Domain\Capability\CapabilityContract;
use Hub\Command\DeviceConfigurationCatalog;

/**
 * O contrato da capacidade `alarm_clock`.
 *
 * Define a forma genérica (`items`, `meta`, `merge`). Uma lista vazia é válida e limpa os
 * alarmes guardados. A conversão por fornecedor é delegada nas implementações de
 * `AlarmClockHandler`.
 */
final class AlarmClockCapability implements CapabilityContract
{
    use AlarmClockHelpers;

    /** @var array<string, AlarmClockHandler> protocol → handler */
    private array $handlers;

    /**
     * @param array<string, AlarmClockHandler> $handlers  Keyed by protocol
     */
    public function __construct(array $handlers = [])
    {
        $this->handlers = $handlers;
    }

    public function key(): string
    {
        return 'alarm_clock';
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
    // delegação nos handlers
    // ------------------------------------------------------------------

    public function toNative(string $protocol, mixed $value): array
    {
        $handler = $this->handlers[$protocol] ?? null;
        if ($handler === null) {
            throw new \InvalidArgumentException("Unsupported protocol {$protocol} for alarm_clock");
        }

        return $handler->toNative($value);
    }

    /**
     * Havia dois: este, que procurava o handler pela chave nativa, e um
     * `fromNativeForProtocol` que procurava pelo protocolo e caía neste quando não achava.
     * Quem escolhia entre os dois era um `instanceof AlarmClockCapability` no registo.
     *
     * O índice por chave nativa não podia funcionar: a Wonlex e a 4P-Touch declaram as duas
     * `alarmClock`, e num mapa com essa chave a segunda apagava a primeira -- os alarmes de
     * um relógio Wonlex saíam pelo descodificador da 4P-Touch. O `fromNativeForProtocol` não
     * era uma excepção à regra, era o penso para este índice.
     *
     * Fica um método só, e a regra é a mesma do `toNative`: quem manda é o protocolo.
     */
    public function fromNative(string $protocol, string $nativeKey, array $desired): mixed
    {
        $handler = $this->handlers[$protocol] ?? null;

        return $handler !== null && $handler->nativeKey() === $nativeKey
            ? $handler->fromNative($desired)
            : [];
    }

    public function defaultValue(string $protocol): mixed
    {
        return $this->handlers[$protocol]?->defaultValue() ?? [];
    }

    // ------------------------------------------------------------------
    // a forma da resposta
    // ------------------------------------------------------------------

    public function meta(string $protocol, array $accumulatedMeta = []): array
    {
        $meta = $this->handlers[$protocol]?->meta($accumulatedMeta) ?? $accumulatedMeta;

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
            'value' => $this->fromNative($protocol, $nativeKey, is_array($value) ? $value : []),
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
