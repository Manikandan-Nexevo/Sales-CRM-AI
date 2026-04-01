<?php
// app/Models/Availability.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\TenantModel;

class Availability extends TenantModel
{
    public $timestamps = false;  // ← ADD THIS too (availability table also has no timestamps)
    protected $table = 'availability';  // prevent Laravel pluralizing to 'availabilities'

    protected $fillable = [
        'user_id',
        'day_of_week',
        'start_time',
        'end_time',
        'timezone',
        'is_active',
    ];
}
