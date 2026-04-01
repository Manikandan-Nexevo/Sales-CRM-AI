<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\TenantModel;

class BookingLink extends TenantModel
{
    public $timestamps = false;  // ← ADD THIS

    protected $fillable = [
        'user_id',
        'slug',
        'duration',
        'is_active',
        'buffer_time',
    ];
}
