<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use Hub\Infrastructure\Persistence\ReferenceCatalogSeeder;
use PDO;

/**
 * Restores the device inventory captured from the production hub so a fresh clone
 * comes up with the real devices, models and gateway links instead of an empty
 * dashboard. The model images live under var/, which is gitignored, so they ship
 * in database/seed-model-images and get copied into place here.
 */
final class Version2026082501SeedDeviceInventory implements Migration
{
    private const SEED_FILE = __DIR__ . '/../../../../database/seed.sql';
    private const IMAGE_SOURCE = __DIR__ . '/../../../../database/seed-model-images';
    private const IMAGE_TARGET = __DIR__ . '/../../../../var/dashboard/model-images';

    public function version(): string
    {
        return '2026082501_seed_device_inventory';
    }

    public function up(PDO $pdo): void
    {
        $seed = file_get_contents(self::SEED_FILE);
        if (!is_string($seed) || trim($seed) === '') {
            throw new \RuntimeException('database seed file is missing or empty');
        }

        $pdo->exec($seed);

        // The models the seed adds arrive after the capability seeding migrations have
        // run, so nothing has given them a template yet and their cards would be empty.
        (new ReferenceCatalogSeeder())->seedMissingModelCapabilities($pdo);

        $this->copyModelImages();
    }

    private function copyModelImages(): void
    {
        if (!is_dir(self::IMAGE_SOURCE)) {
            return;
        }

        if (!is_dir(self::IMAGE_TARGET) && !mkdir(self::IMAGE_TARGET, 0o775, true) && !is_dir(self::IMAGE_TARGET)) {
            throw new \RuntimeException('could not create ' . self::IMAGE_TARGET);
        }

        foreach (glob(self::IMAGE_SOURCE . '/*.jpg') ?: [] as $image) {
            $target = self::IMAGE_TARGET . '/' . basename($image);
            // Never clobber an image the dashboard has since replaced.
            if (!file_exists($target)) {
                copy($image, $target);
            }
        }
    }
}
