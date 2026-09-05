<?php

namespace Hub\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;

final class TimestampFormatter
{
    private const DB_FORMAT = 'Y-m-d H:i:s';
    private const ISO_FORMAT = 'Y-m-d\TH:i:s\Z';

    public static function toIso(?string $value = null): string
    {
        $dateTime = self::parse($value);
        if ($dateTime === null) {
            return gmdate(self::ISO_FORMAT);
        }

        return $dateTime->setTimezone(new DateTimeZone('UTC'))->format(self::ISO_FORMAT);
    }

    /**
     * Como o `toIso`, mas a ausência continua ausência.
     *
     * O `toIso` devolve o instante actual quando não lhe dão nada, o que serve para colunas
     * `NOT NULL` mas mentia num `applied_at` por aplicar. As colunas do ciclo de vida da
     * configuração são `DATETIME NULL`, e a API diz "ainda não" com cadeia vazia.
     */
    public static function toIsoOrEmpty(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

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
                $row[$column] = self::toIsoOrEmpty($row[$column]);
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
        } catch (\Throwable) {
            return null;
        }
    }
}
