<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    protected $table = 'events';

    protected $fillable = [
        'imei',
        'type',
        'payload',
        'recorded_at',
    ];

    public $timestamps = false;
}
