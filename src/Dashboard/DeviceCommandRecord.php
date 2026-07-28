<?php

namespace Hub\Dashboard;

final class DeviceCommandRecord
{
    public const BASE64_ENCODING = 'base64';

    public static function makeJsonSafe(array $record): array
    {
        $bytes = $record['bytes'] ?? null;
        if (!is_string($bytes) || preg_match('//u', $bytes) === 1) {
            return $record;
        }

        $record['bytes'] = base64_encode($bytes);
        $record['bytesEncoding'] = self::BASE64_ENCODING;

        return $record;
    }

    public static function wireBytes(array $record): string
    {
        $bytes = (string)($record['bytes'] ?? '');
        if (($record['bytesEncoding'] ?? null) !== self::BASE64_ENCODING) {
            return $bytes;
        }

        $decoded = base64_decode($bytes, true);

        return is_string($decoded) ? $decoded : '';
    }
}
