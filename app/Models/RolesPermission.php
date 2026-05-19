<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RolesPermission extends Model
{
    protected $table = 'roles_permissions';  

    protected $fillable = [
        'user_id',
        'sales_crm',
        'project_management_tool',
        'status'
    ];

    protected $casts = [
        'sales_crm' => 'array',
        'project_management_tool' => 'array',
    ];
}
