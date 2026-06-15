<?php

namespace Tests\Unit\Dashboard;

use Hub\Dashboard\DatabaseStore;
use PHPUnit\Framework\TestCase;

final class DatabaseStoreTest extends TestCase
{
    public function testSeedsDefaultModelsWhenSuppliersAlreadyExist(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hub-dashboard-');
        self::assertIsString($path);

        try {
            $store = new DatabaseStore($path);
            self::assertSame(5, count($store->models()));

            $store = new DatabaseStore($path);
            self::assertSame(5, count($store->models()));
        } finally {
            unlink($path);
        }
    }

    public function testModelImagePathIsStoredAndPreservedWhenNoReplacementIsProvided(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hub-dashboard-');
        self::assertIsString($path);

        try {
            $store = new DatabaseStore($path);
            $supplier = $store->supplierFind('Wonlex');
            self::assertIsArray($supplier);

            $store->addModel((int)$supplier['id'], 'HW20PRO', 'wonlex-json', '/model-images/example.jpg');
            $model = $store->findModel('Wonlex', 'HW20PRO');
            self::assertSame('/model-images/example.jpg', $model['image_path'] ?? null);

            $store->addModel((int)$supplier['id'], 'HW20PRO', 'wonlex-json-v2');
            $model = $store->findModel('Wonlex', 'HW20PRO');
            self::assertSame('wonlex-json-v2', $model['protocol'] ?? null);
            self::assertSame('/model-images/example.jpg', $model['image_path'] ?? null);
        } finally {
            unlink($path);
        }
    }

    public function testExistingModelCanBeUpdatedById(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hub-dashboard-');
        self::assertIsString($path);

        try {
            $store = new DatabaseStore($path);
            $supplier = $store->supplierFind('Vivistar');
            self::assertIsArray($supplier);
            $model = $store->findModel('Wonlex', 'HW20PRO');
            self::assertIsArray($model);

            $updated = $store->updateModel((int)$model['id'], (int)$supplier['id'], 'VIVISTAR-PRO', 'vivistar-iw', '/model-images/new.jpg');
            self::assertTrue($updated);

            self::assertNull($store->findModel('Wonlex', 'HW20PRO'));
            $model = $store->findModel('Vivistar', 'VIVISTAR-PRO');
            self::assertIsArray($model);
            self::assertSame('vivistar-iw', $model['protocol'] ?? null);
            self::assertSame('/model-images/new.jpg', $model['image_path'] ?? null);
            self::assertTrue($store->modelExistsForDifferentId((int)$model['id'] + 100, (int)$supplier['id'], 'VIVISTAR-PRO'));
            self::assertFalse($store->modelExistsForDifferentId((int)$model['id'], (int)$supplier['id'], 'VIVISTAR-PRO'));
        } finally {
            unlink($path);
        }
    }
}
