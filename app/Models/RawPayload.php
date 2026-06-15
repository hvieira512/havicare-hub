<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RawPayload extends Model
{
    protected $table = 'raw_payloads';

    protected $fillable = [
        'imei',
        'payload',
        'recorded_at',
    ];

    public $timestamps = false;
}
