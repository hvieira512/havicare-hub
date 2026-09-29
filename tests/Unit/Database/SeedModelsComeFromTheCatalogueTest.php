<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Hub\Infrastructure\Persistence\ReferenceCatalogSeeder;
use PHPUnit\Framework\TestCase;

/**
 * Há uma só lista de modelos, e é a do `ReferenceCatalogSeeder`.
 *
 * O `seed.sql` teve a sua durante algum tempo, e as duas divergiram: o dispensador ficou só
 * numa e sete relógios só na outra, sem nada a denunciá-lo. Uma instalação nova nascia com
 * treze modelos e produção tinha vinte e um.
 */
final class SeedModelsComeFromTheCatalogueTest extends TestCase
{
    private const SEED = __DIR__ . '/../../../database/seed.sql';

    /** O ficheiro do inventário não volta a trazer uma lista de modelos. */
    public function testTheInventorySeedDoesNotWriteModels(): void
    {
        $sql = (string)file_get_contents(self::SEED);

        self::assertStringNotContainsString('INTO models', $sql);
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
