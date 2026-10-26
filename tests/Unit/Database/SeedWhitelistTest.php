<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;

/** Cada dispositivo do seed nomeia a empresa e a licença juntas, ou nenhuma: o par resolve-se para a licença. */
final class SeedWhitelistTest extends TestCase
{
    private const SEED = __DIR__ . '/../../../database/seed.sql';

    /** @return list<array{imei: string, licenseId: string, company: string}> */
    private static function whitelistRows(): array
    {
        $sql = (string)file_get_contents(self::SEED);
        $start = strpos($sql, 'INSERT IGNORE INTO whitelist');
        self::assertNotFalse($start, 'o seed tem de continuar a semear a whitelist');

        $block = substr($sql, $start);
        $block = substr($block, 0, (int)strpos($block, ';'));

        $block = preg_replace('/ AS \w+/', '', $block) ?? $block;
        preg_match_all("/SELECT\s*'([^']*)'\s*,[^,]*,[^,]*,[^,]*,\s*([^,]+),[^,]*,[^,]*,\s*([^\n]+)/", $block, $m, PREG_SET_ORDER);
        self::assertNotEmpty($m, 'nenhuma linha reconhecida no bloco da whitelist');

        return array_map(static fn(array $row): array => [
            'imei' => $row[1],
            'licenseId' => trim($row[2]),
            'company' => trim($row[3]),
        ], $m);
    }

    /** O `0` e o `'null'` são de memória; na base é `NULL`. */
    public function testTheSeedNeverStoresTheInMemorySentinels(): void
    {
        $offenders = [];
        foreach (self::whitelistRows() as $row) {
            if ($row['company'] === "'null'" || $row['licenseId'] === '0') {
                $offenders[] = $row['imei'];
            }
        }

        self::assertSame([], $offenders, 'estas linhas gravam sentinelas em vez de NULL');
    }

    /** Uma licença só identifica um inquilino no par empresa+número. */
    public function testNoRowHasALicenceWithoutACompanyOrTheReverse(): void
    {
        $offenders = [];
        foreach (self::whitelistRows() as $row) {
            $withoutLicense = strtoupper($row['licenseId']) === 'NULL';
            $withoutCompany = strtoupper($row['company']) === 'NULL';
            if ($withoutLicense !== $withoutCompany) {
                $offenders[] = "{$row['imei']} (licenca={$row['licenseId']} empresa={$row['company']})";
            }
        }

        self::assertSame([], $offenders, 'ou tem as duas, ou não tem nenhuma');
    }
}
