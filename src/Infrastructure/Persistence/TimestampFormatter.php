<?php

namespace Hub\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;

final class TimestampFormatter
{
    private const DB_FORMAT = 'Y-m-d H:i:s';
    private const ISO_FORMAT = 'Y-m-d\TH:i:s\Z';

    public static function toDatabase(?string $value = null): string
    {
        $dateTime = self::parse($value);
        if ($dateTime === null) {
            return gmdate(self::DB_FORMAT);
        }

        return $dateTime->setTimezone(new DateTimeZone('UTC'))->format(self::DB_FORMAT);
    }

    public static function toIso(?string $value = null): string
    {
        $dateTime = self::parse($value);
        if ($dateTime === null) {
            return gmdate(self::ISO_FORMAT);
        }

        return $dateTime->setTimezone(new DateTimeZone('UTC'))->format(self::ISO_FORMAT);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function normalizeRow(array $row): array
    {
        if (array_key_exists('created_at', $row)) {
            $row['created_at'] = self::toIso(is_string($row['created_at']) ? $row['created_at'] : null);
        }

        if (array_key_exists('updated_at', $row)) {
            $row['updated_at'] = self::toIso(is_string($row['updated_at']) ? $row['updated_at'] : null);
        }

        return $row;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function normalizeRows(array $rows): array
    {
        return array_map(static fn (array $row): array => self::normalizeRow($row), $rows);
    }

    private static function parse(?string $value): ?DateTimeImmutable
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        $timezone = new DateTimeZone('UTC');
        foreach ([self::ISO_FORMAT, self::DB_FORMAT] as $format) {
            $dateTime = DateTimeImmutable::createFromFormat($format, $value, $timezone);
            if ($dateTime instanceof DateTimeImmutable) {
                return $dateTime->setTimezone($timezone);
            }
        }

        try {
            return new DateTimeImmutable($value, $timezone);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
