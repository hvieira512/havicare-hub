<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelemetryEvent extends Model
{
    protected $table = 'telemetry';

    protected $fillable = [
        'imei',
        'type',
        'payload',
        'recorded_at',
    ];

    public $timestamps = false;
}
