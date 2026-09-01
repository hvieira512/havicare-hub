<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;

/**
 * O seed não pode escrever na base os sentinelas que só valem em memória.
 *
 * O hub diz "sem licença" com um `0` e "sem empresa" com o texto `'null'` enquanto o valor
 * viaja -- é o que o ficheiro da whitelist escreve e o que a API aceita --, e converte os
 * dois na fronteira: o `WhitelistRepository` tem o `storedLicenseId()` e o `storedCompany()`
 * precisamente para eles não chegarem às colunas. Na base, sem dono é `NULL`.
 *
 * O seed saltava essa fronteira, e via-se: o filtro de licenças mostrava uma empresa chamada
 * "Sem empresa" com uma licença "Sem Licença" lá dentro, porque uma empresa cujo nome é o
 * texto `null` é, para todo o resto do sistema, uma empresa a sério. Só aparecia onde o seed
 * tinha corrido -- a produção, alimentada pelo código, nunca teve o problema.
 *
 * Lê-se o ficheiro como texto e não se corre nada: é uma leitura de conteúdo, e o que se
 * quer trancar é o que lá está escrito.
 */
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

        preg_match_all("/\(\s*'([^']*)'\s*,[^,]*,[^,]*,[^,]*,\s*([^,]+),[^,]*,[^,]*,\s*([^)]+)\)/", $block, $m, PREG_SET_ORDER);
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

    /**
     * Uma licença não existe sem a empresa a que pertence.
     *
     * A regra é do domínio: o número da licença identifica-a dentro de um CRM, e é o par
     * empresa+número que aponta para um inquilino. Metade do par não quer dizer nada, e o
     * seed tinha uma linha com a licença 1001 e a empresa a `null`.
     */
    public function testNoRowHasALicenceWithoutACompanyOrTheReverse(): void
    {
        $offenders = [];
        foreach (self::whitelistRows() as $row) {
            $semLicenca = strtoupper($row['licenseId']) === 'NULL';
            $semEmpresa = strtoupper($row['company']) === 'NULL';
            if ($semLicenca !== $semEmpresa) {
                $offenders[] = "{$row['imei']} (licenca={$row['licenseId']} empresa={$row['company']})";
            }
        }

        self::assertSame([], $offenders, 'ou tem as duas, ou não tem nenhuma');
    }
}
