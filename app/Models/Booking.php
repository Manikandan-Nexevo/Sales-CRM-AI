<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\TenantModel;

class Booking extends TenantModel
{
    public $timestamps = false;  // ← ADD THIS

    protected $fillable = [
        'user_id',
        'contact_id',
        'name',
        'email',
        'start_time',
        'end_time',
        'meeting_link',
        'status',
        'timezone',

        'cancelled_at',
    ];
}
