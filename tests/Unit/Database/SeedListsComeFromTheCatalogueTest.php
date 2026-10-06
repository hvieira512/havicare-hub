<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Hub\Infrastructure\Persistence\ReferenceCatalogSeeder;
use PHPUnit\Framework\TestCase;

/** Há uma só lista de fornecedores e de modelos, e é a do `ReferenceCatalogSeeder`. */
final class SeedListsComeFromTheCatalogueTest extends TestCase
{
    private const SEED = __DIR__ . '/../../../database/seed.sql';

    /** O ficheiro do inventário não traz lista de modelos. */
    public function testTheInventorySeedDoesNotWriteModels(): void
    {
        $sql = (string)file_get_contents(self::SEED);

        self::assertStringNotContainsString('INTO models', $sql);
    }

    /** Nem de fornecedores: o catálogo escreve-os antes de este ficheiro correr. */
    public function testTheInventorySeedDoesNotWriteSuppliers(): void
    {
        $sql = (string)file_get_contents(self::SEED);

        self::assertStringNotContainsString('INTO suppliers', $sql);
    }

    /** E todo o dispositivo do inventário tem o modelo dele no catálogo. */
    public function testEveryInventoryModelIsInTheCatalogue(): void
    {
        $known = [];
        foreach (ReferenceCatalogSeeder::models() as [$supplier, $internal]) {
            $known["{$supplier}|{$internal}"] = true;
        }

        $missing = [];
        foreach (self::whitelistModels() as $pair) {
            if (!isset($known[$pair])) {
                $missing[] = $pair;
            }
        }

        self::assertSame([], $missing);
    }

    /**
     * Os pares fornecedor/modelo que a whitelist do seed nomeia.
     *
     * @return list<string>
     */
    private static function whitelistModels(): array
    {
        $sql = (string)file_get_contents(self::SEED);
        $start = strpos($sql, 'INSERT IGNORE INTO whitelist');
        self::assertNotFalse($start, 'o seed tem de continuar a semear a whitelist');

        $block = substr($sql, (int)$start);
        $block = substr($block, 0, (int)strpos($block, ';'));

        // Cada linha é `('<imei>', '<fornecedor>', '<modelo>', '<tipo>', ...)`: interessam o
        // segundo e o terceiro campos.
        $pairs = [];
        foreach (explode("\n", $block) as $line) {
            if (preg_match("/^\s*\('[^']*',\s*'([^']+)',\s*'([^']+)'/", $line, $found) === 1) {
                $pairs[$found[1] . '|' . $found[2]] = true;
            }
        }
        self::assertNotSame([], $pairs, 'a whitelist do seed tem de nomear modelos');

        return array_keys($pairs);
    }
}
