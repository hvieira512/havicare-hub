<?php

declare(strict_types=1);

namespace Hub\Command\Downlink;

use Hub\Protocol\Adapter\FourPTouchAdapter;

/**
 * A descida dos relógios 4P Touch.
 */
final class FourPTouchDownlink
{
    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     */
    public static function build(
        string $imei,
        string $command,
        array $entry,
        array $payload = [],
        array $context = []
    ): string {
        $deviceId = trim((string)($context['deviceId'] ?? ''));
        if ($deviceId === '') {
            $deviceId = trim((string)($payload['deviceId'] ?? ''));
        }
        if ($deviceId === '') {
            $deviceId = self::deriveDeviceId($imei);
        }

        return (new FourPTouchAdapter())->encodeOutgoing([
            'type' => $command,
            'imei' => $deviceId,
            'manufacturer' => (string)($payload['manufacturer'] ?? '3G'),
            'data' => ['fields' => $payload['fields'] ?? ($entry['data'] ?? [])],
        ]);
    }

    public static function deriveDeviceId(string $imei): string
    {
        $digits = preg_replace('/\D+/', '', $imei) ?? '';
        if (strlen($digits) === 15) {
            return substr($digits, 4, 10);
        }

        if (strlen($digits) === 10) {
            return $digits;
        }

        if (strlen($digits) > 10) {
            return substr($digits, -10);
        }

        return $digits;
    }
}
