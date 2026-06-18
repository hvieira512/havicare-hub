<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhitelistEntry extends Model
{
    protected $table = 'whitelist';

    protected $primaryKey = 'imei';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'imei',
        'supplier',
        'model',
        'device_type',
        'license_id',
        'sim_number',
        'device_id',
        'source_system',
        'source_device_id',
    ];
}
