<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\TenantModel;

class CalendarEvent extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'reference_id',
        'title',
        'start_time',
        'end_time',
        'status'
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
    ];
}
