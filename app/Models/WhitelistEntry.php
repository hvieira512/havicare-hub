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
    ];
}
