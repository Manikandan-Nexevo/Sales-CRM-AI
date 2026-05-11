<?php
// app/Models/Availability.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\TenantModel;

class RolesPermission extends TenantModel
{
    protected $table = 'roles_permissions';  

    protected $fillable = [
        'user_id',
        'availability',
        'my_bookings',
        'settings',
        'whatsapp',
        'ai_assistant',
        'email',
        'status'
    ];

    protected $casts = [
        'contacts'  => 'array',
        'call_logs' => 'array',
        'follow_ups'     => 'array',
        'teams'     => 'array',
    ];
}
