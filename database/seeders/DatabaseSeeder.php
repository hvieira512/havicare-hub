<?php

namespace Database\Seeders;

use App\Models\Supplier;
use App\Models\DeviceModel;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    private const DEFAULT_MODELS = [
        ['Wonlex', 'HW20PRO', 'wonlex-json', ''],
        ['Wonlex', 'L08 Pro', 'wonlex-json', ''],
        ['Vivistar', 'VIVISTAR-CARE', 'vivistar-iw', ''],
        ['Vivistar', 'VIVISTAR-LITE', 'vivistar-iw', ''],
        ['4P Touch', '4P-TOUCH', 'four-p-touch', ''],
    ];

    public function run(): void
    {
        if (DeviceModel::count() > 0) {
            return;
        }

        $suppliers = [];
        foreach (self::DEFAULT_MODELS as [$supplierName, $model, $protocol, $imagePath]) {
            if (!isset($suppliers[$supplierName])) {
                $suppliers[$supplierName] = Supplier::create(['name' => $supplierName]);
            }
            DeviceModel::create([
                'supplier_id' => $suppliers[$supplierName]->id,
                'model' => $model,
                'protocol' => $protocol,
                'image_path' => $imagePath,
            ]);
        }
    }
}
