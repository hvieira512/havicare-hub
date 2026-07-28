<?php

namespace Hub\Command;

use Hub\Command\Configuration\Definition\FourPTouchConfigurationDefinitions;
use Hub\Command\Configuration\Definition\VivistarConfigurationDefinitions;
use Hub\Command\Configuration\Definition\WonlexConfigurationDefinitions;
use Hub\Command\Configuration\Payload\FourPTouchPayloadBuilder;
use Hub\Command\Configuration\Payload\VivistarPayloadBuilder;
use Hub\Command\Configuration\Payload\WonlexPayloadBuilder;
use Hub\Domain\Capability\FourPTouch\FourPTouchGenericHandler;

final class DeviceConfigurationCatalog
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function configsForProtocol(string $protocol): array
    {
        $configs = match ($protocol) {
            'wonlex-json' => WonlexConfigurationDefinitions::all(),
            'vivistar-iw' => VivistarConfigurationDefinitions::all(),
            'four-p-touch' => FourPTouchConfigurationDefinitions::all(),
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
        $key = self::resolvePublicKeyAlias($protocol, $key);
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
        $commands = self::commandPayloads($protocol, $key, $payload);
        if (count($commands) !== 1) {
            throw new \InvalidArgumentException("{$key} produces multiple device commands");
        }

        return $commands[0];
    }

    /**
     * Some public configurations expand to several protocol commands. Wonlex
     * medication plans are one such case: the watch accepts one plan per frame.
     *
     * @return list<array{command: string, payload: array<string, mixed>}>
     */
    public static function commandPayloads(string $protocol, string $key, array $payload): array
    {
        $key = self::resolvePublicKeyAlias($protocol, $key);
        $entry = self::configForProtocol($protocol, $key);
        if ($entry === null) {
            throw new \InvalidArgumentException("Unsupported {$protocol} configuration {$key}");
        }

        $payloads = [$payload];
        if ($protocol === 'wonlex-json' && $key === 'dnMedicationPlan' && isset($payload['plans'])) {
            if (!is_array($payload['plans']) || $payload['plans'] === []) {
                throw new \InvalidArgumentException('plans must contain at least one medication plan');
            }
            $payloads = array_map(static function (mixed $plan): array {
                if (!is_array($plan)) {
                    throw new \InvalidArgumentException('plans items must be objects');
                }

                return ['plan' => $plan];
            }, array_values($payload['plans']));
        }

        return array_map(static fn(array $item): array => [
            'command' => (string)$entry['command'],
            'payload' => match ($protocol) {
                'wonlex-json' => WonlexPayloadBuilder::build($key, $item),
                'vivistar-iw' => VivistarPayloadBuilder::build($key, $item),
                'four-p-touch' => FourPTouchPayloadBuilder::build($key, $item),
                default => throw new \InvalidArgumentException("Unsupported protocol {$protocol}"),
            },
        ], $payloads);
    }

    public static function validate(string $protocol, string $key, array $payload): ?string
    {
        $key = self::resolvePublicKeyAlias($protocol, $key);
        if (self::configForProtocol($protocol, $key) === null) {
            return "Unsupported {$protocol} configuration {$key}";
        }

        try {
            self::commandPayloads($protocol, $key, $payload);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    private static function resolvePublicKeyAlias(string $protocol, string $key): string
    {
        $key = trim($key);
        if ($protocol === 'four-p-touch') {
            return FourPTouchGenericHandler::publicKeyToNativeKey($key) ?? $key;
        }
        if ($key === 'alarm_clock') {
            return match ($protocol) {
                'vivistar-iw' => 'reminders',
                'wonlex-json' => 'alarmClock',
                default => $key,
            };
        }
        if ($key === 'fall_detection') {
            return $protocol === 'vivistar-iw' ? 'fallDetection' : $key;
        }
        if ($key === 'fall_sensitivity') {
            return $protocol === 'vivistar-iw' ? 'fallSensitivity' : $key;
        }
        if ($key === 'firmwareVersion') {
            return $key;
        }
        if ($key === 'deviceStatus') {
            return $key;
        }

        return $key;
    }
}
