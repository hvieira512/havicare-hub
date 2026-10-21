<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;

final class TimestampFormatter
{
    private const DB_FORMAT = 'Y-m-d H:i:s';
    private const ISO_FORMAT = 'Y-m-d\TH:i:s\Z';

    /** A ausência continua ausência: um instante por preencher não é o de agora. */
    public static function toIso(mixed $value): string
    {
        $dateTime = self::parse(is_string($value) ? $value : null);

        return $dateTime === null
            ? ''
            : $dateTime->setTimezone(new DateTimeZone('UTC'))->format(self::ISO_FORMAT);
    }

    /**
     * Converte para ISO as colunas de instante que a linha tenha, deixando as ausentes vazias.
     *
     * @param array<string, mixed> $row
     * @param list<string> $columns
     * @return array<string, mixed>
     */
    public static function isoColumns(array $row, array $columns): array
    {
        foreach ($columns as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = self::toIso($row[$column]);
            }
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function normalizeRow(array $row): array
    {
        return self::isoColumns($row, ['created_at', 'updated_at']);
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
        } catch (\Throwable) {
            return null;
        }
    }
}
