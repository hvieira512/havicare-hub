<?php

namespace Hub\Command;

use Hub\Command\Configuration\Definition\FourPTouchConfigurationDefinitions;
use Hub\Command\Configuration\Definition\VivistarConfigurationDefinitions;
use Hub\Command\Configuration\Definition\WonlexConfigurationDefinitions;
use Hub\Command\Configuration\Payload\FourPTouchPayloadBuilder;
use Hub\Command\Configuration\Payload\VivistarPayloadBuilder;
use Hub\Command\Configuration\Payload\WonlexPayloadBuilder;

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
        $key = self::resolvePublicKeyAlias($protocol, $key);
        $entry = self::configForProtocol($protocol, $key);
        if ($entry === null) {
            throw new \InvalidArgumentException("Unsupported {$protocol} configuration {$key}");
        }

        return [
            'command' => (string)$entry['command'],
            'payload' => match ($protocol) {
                'wonlex-json' => WonlexPayloadBuilder::build($key, $payload),
                'vivistar-iw' => VivistarPayloadBuilder::build($key, $payload),
                'four-p-touch' => FourPTouchPayloadBuilder::build($key, $payload),
                default => throw new \InvalidArgumentException("Unsupported protocol {$protocol}"),
            },
        ];
    }

    public static function validate(string $protocol, string $key, array $payload): ?string
    {
        $key = self::resolvePublicKeyAlias($protocol, $key);
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

    private static function resolvePublicKeyAlias(string $protocol, string $key): string
    {
        $key = trim($key);
        if ($key === 'alarm_clock') {
            return match ($protocol) {
                'vivistar-iw' => 'reminders',
                'four-p-touch' => 'alarmClock',
                default => $key,
            };
        }
        if ($key === 'fall_sensitivity') {
            return match ($protocol) {
                'vivistar-iw' => 'fallSensitivity',
                'four-p-touch' => 'fallDownSensitivity',
                default => $key,
            };
        }
        if ($key === 'location_reporting_interval') {
            return match ($protocol) {
                'four-p-touch' => 'uploadInterval',
                default => $key,
            };
        }
        if ($key === 'monitor_number') {
            return match ($protocol) {
                'four-p-touch' => 'monitorNumber',
                default => $key,
            };
        }
        if ($key === 'center_number') {
            return match ($protocol) {
                'four-p-touch' => 'centerNumber',
                default => $key,
            };
        }
        if ($key === 'sos_sms_alert') {
            return match ($protocol) {
                'four-p-touch' => 'sosSmsAlerts',
                default => $key,
            };
        }
        if ($key === 'low_battery_alert') {
            return match ($protocol) {
                'four-p-touch' => 'lowBatterySmsAlerts',
                default => $key,
            };
        }
        if ($key === 'remove_watch_alarm') {
            return match ($protocol) {
                'four-p-touch' => 'removeWatchAlarm',
                default => $key,
            };
        }
        if ($key === 'remove_watch_sms_alert') {
            return match ($protocol) {
                'four-p-touch' => 'removeWatchSmsAlerts',
                default => $key,
            };
        }
        if ($key === 'fall_detection') {
            return match ($protocol) {
                'four-p-touch' => 'fallDownAlert',
                default => $key,
            };
        }
        if ($key === 'medication_reminders') {
            return match ($protocol) {
                'four-p-touch' => 'takePills',
                default => $key,
            };
        }
        if ($key === 'auto_vitals_interval') {
            return match ($protocol) {
                'four-p-touch' => 'healthAutoMeasurement',
                default => $key,
            };
        }
        if ($key === 'pedometer_schedule') {
            return match ($protocol) {
                'four-p-touch' => 'walkTime',
                default => $key,
            };
        }
        if ($key === 'sleep_monitoring') {
            return match ($protocol) {
                'four-p-touch' => 'sleepTime',
                default => $key,
            };
        }
        if ($key === 'temperature_measurement_interval') {
            return match ($protocol) {
                'four-p-touch' => 'bodyTemperatureInterval',
                default => $key,
            };
        }
        if ($key === 'power_off') {
            return match ($protocol) {
                'four-p-touch' => 'powerOffCommand',
                default => $key,
            };
        }
        if ($key === 'push_message') {
            return match ($protocol) {
                'four-p-touch' => 'pushMessage',
                default => $key,
            };
        }
        if ($key === 'make_call') {
            return match ($protocol) {
                'four-p-touch' => 'makeCall',
                default => $key,
            };
        }
        if ($key === 'reset_device') {
            return match ($protocol) {
                'four-p-touch' => 'resetCommand',
                default => $key,
            };
        }
        if ($key === 'firmwareVersion') {
            return match ($protocol) {
                'four-p-touch' => 'firmwareVersion',
                default => $key,
            };
        }
        if ($key === 'deviceStatus') {
            return match ($protocol) {
                'four-p-touch' => 'deviceStatus',
                default => $key,
            };
        }
        if ($key === 'device_password') {
            return match ($protocol) {
                'four-p-touch' => 'devicePassword',
                default => $key,
            };
        }
        if ($key === 'language_timezone') {
            return match ($protocol) {
                'four-p-touch' => 'languageTimezone',
                default => $key,
            };
        }
        if ($key === 'call_in_restriction') {
            return match ($protocol) {
                'four-p-touch' => 'callInRestriction',
                default => $key,
            };
        }
        if ($key === 'sound_profile') {
            return match ($protocol) {
                'four-p-touch' => 'soundProfile',
                default => $key,
            };
        }
        if ($key === 'do_not_disturb') {
            return match ($protocol) {
                'four-p-touch' => 'doNotDisturb',
                default => $key,
            };
        }

        return $key;
    }
}
